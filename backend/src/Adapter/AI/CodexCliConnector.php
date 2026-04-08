<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Adapter\AI;

use App\Enum\ConnectorType;
use App\ValueObject\ConnectorAuthStatus;
use App\ValueObject\ConnectorConfig;
use App\ValueObject\ConnectorHealthCheckResult;
use App\ValueObject\ConnectorRequest;
use App\ValueObject\ConnectorResponse;
use App\ValueObject\AgentModelInfo;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Connector implementation using the local Codex CLI.
 */
class CodexCliConnector extends AbstractConnector
{
    /**
     * Executes Codex CLI non-interactively and normalizes the emitted JSONL events.
     */
    public function sendRequest(ConnectorRequest $request, ConnectorConfig $config): ConnectorResponse
    {
        $start = microtime(true);
        $modelFlag = $config->model !== null ? sprintf(' --model %s', escapeshellarg($config->model)) : '';
        $process = new Process(
            [
                'sh', '-lc',
                sprintf(
                    'HOME=/codex-home codex exec --json --skip-git-repo-check --sandbox read-only%s -C %s %s',
                    $modelFlag,
                    escapeshellarg($request->workingDirectory),
                    escapeshellarg($request->prompt->build()),
                ),
            ],
            $request->workingDirectory,
            null,
            null,
            $config->timeout,
        );
        $process->run();

        $durationMs = (microtime(true) - $start) * 1000;

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $rawOutput = trim($process->getOutput());
        $decoded = $this->decodeJsonLines($rawOutput);

        return ConnectorResponse::fromCliJson(
            content: $decoded['content'],
            durationMs: $durationMs,
            usage: $decoded['usage'],
            metadata: [
                'source' => 'codex_cli',
                'session_id' => $decoded['session_id'],
                'model' => $config->model,
            ],
            rawOutput: $rawOutput,
        );
    }

    /**
     * Returns the normalized Codex CLI login status.
     */
    public function getAuthenticationStatus(): ConnectorAuthStatus
    {
        $process = new Process(
            ['sh', '-lc', 'HOME=/codex-home codex login status'],
            '/var/www/backend',
            timeout: 10,
        );
        $process->run();

        $combinedOutput = trim($process->getOutput());
        $errorOutput = trim($process->getErrorOutput());
        $normalizedOutput = strtolower($combinedOutput . "\n" . $errorOutput);
        $authMethod = $this->detectAuthMethod($normalizedOutput);
        $authenticated = $authMethod !== null;
        $usesAccountUsage = $authMethod === 'chatgpt';

        if (!$process->isSuccessful() && !$authenticated) {
            return new ConnectorAuthStatus(
                required: true,
                authenticated: false,
                status: 'missing',
                method: null,
                supportsAccountUsage: true,
                usesAccountUsage: false,
                summary: 'Codex CLI is not logged in.',
                error: $errorOutput !== '' ? $errorOutput : ($combinedOutput !== '' ? $combinedOutput : 'Codex CLI auth status failed.'),
                fixCommand: 'php scripts/codex-auth.php login',
                metadata: [
                    'stdout' => $combinedOutput !== '' ? $combinedOutput : null,
                    'stderr' => $errorOutput !== '' ? $errorOutput : null,
                ],
            );
        }

        if ($authMethod === 'api_key') {
            return new ConnectorAuthStatus(
                required: true,
                authenticated: true,
                status: 'misconfigured',
                method: 'api_key',
                supportsAccountUsage: true,
                usesAccountUsage: false,
                summary: 'Codex CLI is logged in with an API key, so it would consume API credits instead of ChatGPT plan usage.',
                metadata: [
                    'stdout' => $combinedOutput !== '' ? $combinedOutput : null,
                    'stderr' => $errorOutput !== '' ? $errorOutput : null,
                ],
            );
        }

        return new ConnectorAuthStatus(
            required: true,
            authenticated: $authenticated,
            status: $authenticated ? 'ok' : 'missing',
            method: $authMethod,
            supportsAccountUsage: true,
            usesAccountUsage: $usesAccountUsage,
            summary: $authenticated
                ? 'Codex CLI is authenticated through ChatGPT account login.'
                : 'Codex CLI is not logged in.',
            error: !$authenticated && $errorOutput !== '' ? $errorOutput : null,
            fixCommand: $authenticated ? null : 'php scripts/codex-auth.php login',
            metadata: [
                'stdout' => $combinedOutput !== '' ? $combinedOutput : null,
                'stderr' => $errorOutput !== '' ? $errorOutput : null,
            ],
        );
    }

    protected function connectorType(): ConnectorType
    {
        return ConnectorType::CodexCli;
    }

    protected function checkRuntime(): ConnectorHealthCheckResult
    {
        $process = new Process(['sh', '-lc', 'HOME=/codex-home codex --version'], '/var/www/backend', null, null, 5);
        $process->run();

        if ($process->isSuccessful()) {
            return new ConnectorHealthCheckResult(
                name: 'runtime',
                status: 'ok',
                summary: trim($process->getOutput()) ?: 'Codex CLI is reachable.',
            );
        }

        $whichProcess = new Process(['sh', '-lc', 'which codex 2>/dev/null'], '/var/www/backend', null, null, 5);
        $whichProcess->run();
        if (!$whichProcess->isSuccessful() || trim($whichProcess->getOutput()) === '') {
            return new ConnectorHealthCheckResult(
                name: 'runtime',
                status: 'degraded',
                summary: 'Binary not found.',
                fixCommand: 'php scripts/install-clients.php codex --docker',
            );
        }

        return new ConnectorHealthCheckResult(
            name: 'runtime',
            status: 'degraded',
            summary: trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Codex CLI runtime check failed.',
        );
    }

    /**
     * Reports whether this connector handles the Codex CLI runtime.
     */
    public function supportsConnector(ConnectorType $type): bool
    {
        return $type === ConnectorType::CodexCli;
    }

    /**
     * Declares that Codex CLI does not expose native runtime model discovery here.
     */
    public function supportsModelDiscovery(): bool
    {
        return false;
    }

    /**
     * Returns no models because Codex CLI does not currently expose a native model catalog command.
     *
     * @return AgentModelInfo[]
     */
    public function discoverModels(): array
    {
        return [];
    }

    /**
     * Collapses the Codex JSONL event stream into one normalized content and usage payload.
     *
     * @return array{content: string, usage: array<string, int>, session_id: string|null}
     */
    private function decodeJsonLines(string $output): array
    {
        if ($output === '') {
            return ['content' => '', 'usage' => [], 'session_id' => null];
        }

        $content = [];
        $usage = [];
        $sessionId = null;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (!is_array($decoded)) {
                continue;
            }

            $type = $decoded['type'] ?? null;

            if ($type === 'thread.started' && is_string($decoded['thread_id'] ?? null)) {
                $sessionId = $decoded['thread_id'];
            }

            if ($type === 'item.completed') {
                $item = $decoded['item'] ?? null;
                if (is_array($item) && ($item['type'] ?? null) === 'agent_message' && is_string($item['text'] ?? null) && $item['text'] !== '') {
                    $content[] = $item['text'];
                }
            }

            if ($type === 'turn.completed' && is_array($decoded['usage'] ?? null)) {
                $usage = [
                    'input_tokens' => (int) ($decoded['usage']['input_tokens'] ?? 0),
                    'output_tokens' => (int) ($decoded['usage']['output_tokens'] ?? 0),
                ];
            }
        }

        return [
            'content' => trim(implode("\n\n", $content)),
            'usage' => $usage,
            'session_id' => $sessionId,
        ];
    }

    private function detectAuthMethod(string $output): ?string
    {
        if (str_contains($output, 'logged in using chatgpt')) {
            return 'chatgpt';
        }

        if (str_contains($output, 'logged in using api key')) {
            return 'api_key';
        }

        return null;
    }
}
