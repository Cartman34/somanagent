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
use App\ValueObject\AgentModelCapabilities;
use App\ValueObject\AgentModelInfo;
use App\ValueObject\AgentModelPricing;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Connector implementation using the local OpenCode CLI.
 */
class OpenCodeCliConnector extends AbstractConnector
{
    /**
     * Executes OpenCode CLI and normalizes its JSON event stream into one response payload.
     */
    public function sendRequest(ConnectorRequest $request, ConnectorConfig $config): ConnectorResponse
    {
        $start = microtime(true);
        $command = ['opencode', 'run', '--format', 'json'];
        if ($config->model !== null) {
            $command[] = '--model';
            $command[] = $config->model;
        }
        $command[] = '--dir';
        $command[] = $request->workingDirectory;
        $command[] = $request->prompt->build();

        $process = new Process(
            $command,
            $request->workingDirectory,
            $this->buildRuntimeEnvironment(),
            null,
            $config->timeout,
        );
        $process->run();

        $durationMs = (microtime(true) - $start) * 1000;

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $rawOutput = trim($process->getOutput());
        $decoded = $this->decodeRunOutput($rawOutput);

        return ConnectorResponse::fromCliJson(
            content: $decoded['content'],
            durationMs: $durationMs,
            usage: $decoded['usage'],
            metadata: [
                'source' => 'opencode_cli',
                'model' => $config->model,
                'session_id' => $decoded['session_id'],
            ],
            rawOutput: $rawOutput,
        );
    }

    /**
     * Returns the normalized OpenCode credential status.
     */
    public function getAuthenticationStatus(): ConnectorAuthStatus
    {
        $process = new Process(
            ['sh', '-lc', 'HOME=/opencode-home XDG_STATE_HOME=/opencode-home/.local/state opencode auth list'],
            '/var/www/backend',
            timeout: 10,
        );
        $process->run();

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());
        $credentialCount = $this->extractCredentialCount($stdout);

        if (!$process->isSuccessful()) {
            return new ConnectorAuthStatus(
                required: true,
                authenticated: false,
                status: 'missing',
                method: null,
                supportsAccountUsage: false,
                usesAccountUsage: false,
                summary: 'OpenCode CLI auth status failed.',
                error: $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'OpenCode CLI auth status failed.'),
                fixCommand: 'php scripts/opencode-auth.php login',
                metadata: [
                    'stdout' => $stdout !== '' ? $stdout : null,
                    'stderr' => $stderr !== '' ? $stderr : null,
                    'credentialCount' => null,
                ],
            );
        }

        if ($credentialCount < 1) {
            return new ConnectorAuthStatus(
                required: true,
                authenticated: false,
                status: 'missing',
                method: null,
                supportsAccountUsage: false,
                usesAccountUsage: false,
                summary: 'OpenCode CLI has no configured provider credential.',
                fixCommand: 'php scripts/opencode-auth.php login',
                metadata: [
                    'stdout' => $stdout !== '' ? $stdout : null,
                    'stderr' => $stderr !== '' ? $stderr : null,
                    'credentialCount' => 0,
                ],
            );
        }

        return new ConnectorAuthStatus(
            required: true,
            authenticated: true,
            status: 'limited',
            method: 'provider_credentials',
            supportsAccountUsage: false,
            usesAccountUsage: false,
            summary: 'OpenCode CLI uses provider credentials; no subscription-based account usage mode was detected.',
            metadata: [
                'stdout' => $stdout !== '' ? $stdout : null,
                'stderr' => $stderr !== '' ? $stderr : null,
                'credentialCount' => $credentialCount,
            ],
        );
    }

    protected function connectorType(): ConnectorType
    {
        return ConnectorType::OpenCodeCli;
    }

    protected function checkRuntime(): ConnectorHealthCheckResult
    {
        $process = new Process(
            ['opencode', '--version'],
            '/var/www',
            $this->buildRuntimeEnvironment(),
            null,
            5,
        );
        $process->run();

        if ($process->isSuccessful()) {
            return new ConnectorHealthCheckResult(
                name: 'runtime',
                status: 'ok',
                summary: trim($process->getOutput()) ?: 'OpenCode CLI is reachable.',
            );
        }

        $result = shell_exec('which opencode 2>/dev/null');
        if ($result === null || trim($result) === '') {
            return new ConnectorHealthCheckResult(
                name: 'runtime',
                status: 'degraded',
                summary: 'Binary not found.',
                fixCommand: 'php scripts/install-clients.php opencode --docker',
            );
        }

        return new ConnectorHealthCheckResult(
            name: 'runtime',
            status: 'degraded',
            summary: trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'OpenCode CLI runtime check failed.',
        );
    }

    /**
     * Reports whether this connector handles the OpenCode CLI runtime.
     */
    public function supportsConnector(ConnectorType $type): bool
    {
        return $type === ConnectorType::OpenCodeCli;
    }

    /**
     * Declares that OpenCode CLI exposes runtime model discovery.
     */
    public function supportsModelDiscovery(): bool
    {
        return true;
    }

    /**
     * Fetches the verbose OpenCode model catalog and maps it to normalized model descriptors.
     *
     * @return AgentModelInfo[]
     */
    public function discoverModels(): array
    {
        $process = new Process(
            ['opencode', 'models', '--verbose'],
            '/var/www',
            $this->buildRuntimeEnvironment(),
            null,
            30,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $this->decodeVerboseModelsOutput(trim($process->getOutput()));
    }

    /**
     * Builds the isolated environment variables required to run OpenCode from the worktree.
     *
     * @return array<string, string>
     */
    private function buildRuntimeEnvironment(): array
    {
        return [
            'HOME' => '/opencode-home',
            'XDG_STATE_HOME' => '/opencode-home/.local/state',
        ];
    }

    /**
     * Collapses the OpenCode JSON event stream into one normalized content and usage payload.
     *
     * @return array{content: string, usage: array<string, int>, session_id: string|null}
     */
    private function decodeRunOutput(string $output): array
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
                $content[] = $line;
                continue;
            }

            if (is_string($decoded['sessionID'] ?? null)) {
                $sessionId = $decoded['sessionID'];
            }

            if (is_string($decoded['text'] ?? null)) {
                $content[] = $decoded['text'];
            }

            if (is_array($decoded['usage'] ?? null)) {
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

    /**
     * Parses the verbose OpenCode catalog output into normalized model descriptors with typed metadata.
     *
     * @return AgentModelInfo[]
     */
    private function decodeVerboseModelsOutput(string $output): array
    {
        if ($output === '') {
            return [];
        }

        $models = [];
        $lines = preg_split('/\R/', $output) ?: [];
        $index = 0;

        while ($index < count($lines)) {
            $modelReference = trim($lines[$index] ?? '');
            ++$index;

            if ($modelReference === '' || str_starts_with($modelReference, 'opencode models')) {
                continue;
            }

            while ($index < count($lines) && trim($lines[$index]) === '') {
                ++$index;
            }

            if (($lines[$index] ?? null) === null || trim($lines[$index]) !== '{') {
                continue;
            }

            $jsonLines = [];
            $depth = 0;

            while ($index < count($lines)) {
                $line = $lines[$index];
                $jsonLines[] = $line;
                $depth += substr_count($line, '{');
                $depth -= substr_count($line, '}');
                ++$index;

                if ($depth <= 0) {
                    break;
                }
            }

            $decoded = json_decode(implode("\n", $jsonLines), true);

            if (!is_array($decoded)) {
                continue;
            }

            $provider = is_string($decoded['providerID'] ?? null) ? $decoded['providerID'] : null;
            $family = is_string($decoded['family'] ?? null) ? $decoded['family'] : null;
            $modelId = is_string($decoded['id'] ?? null) ? $decoded['id'] : $modelReference;
            $fullId = $provider !== null ? sprintf('%s/%s', $provider, $modelId) : $modelReference;
            $cost = is_array($decoded['cost'] ?? null) ? $decoded['cost'] : [];
            $limit = is_array($decoded['limit'] ?? null) ? $decoded['limit'] : [];
            $capabilities = is_array($decoded['capabilities'] ?? null) ? $decoded['capabilities'] : [];
            $isFree = $this->isOpenCodeModelFree($cost, $modelReference, $fullId);

            $models[] = new AgentModelInfo(
                id: $fullId,
                label: is_string($decoded['name'] ?? null) ? $decoded['name'] : $fullId,
                provider: $provider,
                family: $family,
                description: sprintf(
                    'OpenCode model (%s, %s).',
                    is_string($decoded['status'] ?? null) ? $decoded['status'] : 'unknown status',
                    $isFree ? 'free tier' : 'paid tier',
                ),
                contextWindow: is_int($limit['context'] ?? null) ? $limit['context'] : null,
                maxOutputTokens: is_int($limit['output'] ?? null) ? $limit['output'] : null,
                status: is_string($decoded['status'] ?? null) ? $decoded['status'] : null,
                releaseDate: is_string($decoded['release_date'] ?? null) ? $decoded['release_date'] : null,
                pricing: new AgentModelPricing(
                    input: is_numeric($cost['input'] ?? null) ? (float) $cost['input'] : null,
                    output: is_numeric($cost['output'] ?? null) ? (float) $cost['output'] : null,
                    cacheRead: is_numeric($cost['cache']['read'] ?? null) ? (float) $cost['cache']['read'] : null,
                    cacheWrite: is_numeric($cost['cache']['write'] ?? null) ? (float) $cost['cache']['write'] : null,
                    isFree: $isFree,
                ),
                capabilities: new AgentModelCapabilities(
                    reasoning: is_bool($capabilities['reasoning'] ?? null) ? $capabilities['reasoning'] : null,
                    toolCall: is_bool($capabilities['toolcall'] ?? null) ? $capabilities['toolcall'] : null,
                    attachment: is_bool($capabilities['attachment'] ?? null) ? $capabilities['attachment'] : null,
                    temperature: is_bool($capabilities['temperature'] ?? null) ? $capabilities['temperature'] : null,
                    input: is_array($capabilities['input'] ?? null) ? array_filter($capabilities['input'], 'is_bool') : [],
                    output: is_array($capabilities['output'] ?? null) ? array_filter($capabilities['output'], 'is_bool') : [],
                    additional: [
                        'interleaved' => $capabilities['interleaved'] ?? null,
                    ],
                ),
                metadata: [
                    'variants' => $decoded['variants'] ?? null,
                    'limit' => $limit,
                    'api' => $decoded['api'] ?? null,
                ],
            );
        }

        return $models;
    }

    /**
     * Detects whether OpenCode exposes the model as free from either explicit zero pricing or naming hints.
     *
     * @param array<string, mixed> $cost
     */
    private function isOpenCodeModelFree(array $cost, string $modelReference, string $fullId): bool
    {
        $inputCost = (float) ($cost['input'] ?? 0);
        $outputCost = (float) ($cost['output'] ?? 0);
        $readCost = (float) (($cost['cache']['read'] ?? 0));
        $writeCost = (float) (($cost['cache']['write'] ?? 0));
        $normalizedReference = strtolower($modelReference . ' ' . $fullId);

        return ($inputCost === 0.0 && $outputCost === 0.0 && $readCost === 0.0 && $writeCost === 0.0)
            || str_contains($normalizedReference, 'free');
    }

    /**
     * Extracts the credential count announced by the OpenCode CLI summary output.
     */
    private function extractCredentialCount(string $output): int
    {
        if (preg_match('/(\d+)\s+credentials?/i', $output, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
