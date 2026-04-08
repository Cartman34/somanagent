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
 * Connector implementation using the Claude CLI (local process execution).
 */
class ClaudeCliConnector extends AbstractConnector
{
    /**
     * Executes the Claude CLI, parses its output, and normalizes the resulting response.
     */
    public function sendRequest(ConnectorRequest $request, ConnectorConfig $config): ConnectorResponse
    {
        $start = microtime(true);

        // Use absolute binary path and explicit env so that all callers (FPM, CLI, worker)
        // behave identically regardless of the process user or inherited shell environment.
        // --model is omitted when empty so the CLI falls back to its configured default.
        $command = ['/usr/local/bin/claude', '--print', '--no-session-persistence', '--output-format', 'json'];
        if ($config->model !== null) {
            $command[] = '--model';
            $command[] = $config->model;
        }

        $process = new Process(
            $command,
            $request->workingDirectory,
            [
                'HOME'     => '/claude-home',
                'NO_COLOR' => '1',
                'TERM'     => 'dumb',
                'PATH'     => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            ],
            $request->prompt->build(),
            $config->timeout,
        );
        $process->run();

        $durationMs = (microtime(true) - $start) * 1000;

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $output = trim($process->getOutput());
        $decoded = json_decode($output, true);

        if (!is_array($decoded) && $output !== '') {
            // Best-effort fallback for CLI outputs that wrap the JSON payload with extra text.
            // This remains heuristic: if multiple JSON-like fragments are present, parsing may fail.
            $startJson = strpos($output, '{');
            $endJson   = strrpos($output, '}');

            if ($startJson !== false && $endJson !== false && $endJson > $startJson) {
                $decoded = json_decode(substr($output, $startJson, $endJson - $startJson + 1), true);
            }
        }

        if (is_array($decoded)) {
            $content = trim((string) ($decoded['result'] ?? ''));
            $usage   = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];

            return ConnectorResponse::fromCliJson(
                content: $content,
                durationMs: $durationMs,
                usage: $usage,
                metadata: [
                    'source' => 'cli',
                    'stop_reason' => $decoded['stop_reason'] ?? null,
                    'session_id' => $decoded['session_id'] ?? null,
                ],
                rawOutput: $output,
            );
        }

        return ConnectorResponse::fromCli($output, $durationMs, rawOutput: $output);
    }

    /**
     * Returns the Claude CLI authentication status from the runtime home.
     */
    public function getAuthenticationStatus(): ConnectorAuthStatus
    {
        $process = new Process(
            ['/usr/local/bin/claude', 'auth', 'status', '--json'],
            '/var/www/backend',
            [
                'HOME' => '/claude-home',
                'NO_COLOR' => '1',
                'TERM' => 'dumb',
                'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            ],
            null,
            15,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            return new ConnectorAuthStatus(
                required: true,
                authenticated: false,
                status: 'missing',
                method: null,
                supportsAccountUsage: true,
                usesAccountUsage: false,
                summary: 'Claude CLI is not logged in.',
                error: trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Claude CLI auth status failed.',
                fixCommand: 'php scripts/claude-auth.php login',
            );
        }

        $decoded = json_decode($process->getOutput(), true);
        if (!is_array($decoded)) {
            $output = trim($process->getOutput());
            $startJson = strpos($output, '{');
            $endJson = strrpos($output, '}');

            if ($startJson !== false && $endJson !== false && $endJson > $startJson) {
                $decoded = json_decode(substr($output, $startJson, $endJson - $startJson + 1), true);
            }
        }

        if (!is_array($decoded)) {
            return new ConnectorAuthStatus(
                required: true,
                authenticated: false,
                status: 'missing',
                method: null,
                supportsAccountUsage: true,
                usesAccountUsage: false,
                summary: 'Claude CLI auth status could not be parsed.',
                error: 'Claude CLI auth status returned invalid JSON.',
                fixCommand: 'php scripts/claude-auth.php login',
            );
        }

        $loggedIn = (bool) ($decoded['loggedIn'] ?? false);
        $authMethod = isset($decoded['authMethod']) ? (string) $decoded['authMethod'] : null;

        return new ConnectorAuthStatus(
            required: true,
            authenticated: $loggedIn,
            status: $loggedIn ? 'ok' : 'missing',
            method: $authMethod,
            supportsAccountUsage: true,
            usesAccountUsage: $loggedIn && $authMethod !== 'api_key',
            summary: $loggedIn
                ? sprintf('Claude CLI is authenticated via %s.', $authMethod ?? 'claude.ai')
                : 'Claude CLI is not logged in.',
            fixCommand: $loggedIn ? null : 'php scripts/claude-auth.php login',
            metadata: [
                'apiProvider' => isset($decoded['apiProvider']) ? (string) $decoded['apiProvider'] : null,
                'raw' => $decoded,
            ],
        );
    }

    protected function connectorType(): ConnectorType
    {
        return ConnectorType::ClaudeCli;
    }

    protected function checkRuntime(): ConnectorHealthCheckResult
    {
        if (!file_exists('/usr/local/bin/claude')) {
            return new ConnectorHealthCheckResult(
                name: 'runtime',
                status: 'degraded',
                summary: 'Binary not found.',
                fixCommand: 'php scripts/install-clients.php claude --docker',
            );
        }

        $process = new Process(
            ['/usr/local/bin/claude', '--version'],
            '/var/www/backend',
            [
                'HOME' => '/claude-home',
                'NO_COLOR' => '1',
                'TERM' => 'dumb',
                'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            ],
            null,
            5,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            return new ConnectorHealthCheckResult(
                name: 'runtime',
                status: 'degraded',
                summary: trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Claude CLI runtime check failed.',
            );
        }

        return new ConnectorHealthCheckResult(
            name: 'runtime',
            status: 'ok',
            summary: trim($process->getOutput()) ?: 'Claude CLI is reachable.',
        );
    }

    /**
     * Reports whether this connector handles the Claude CLI runtime.
     */
    public function supportsConnector(ConnectorType $type): bool
    {
        return $type === ConnectorType::ClaudeCli;
    }

    /**
     * Indicates that Claude CLI model discovery is not implemented in this adapter yet.
     */
    public function supportsModelDiscovery(): bool
    {
        return false;
    }

    /**
     * @return AgentModelInfo[]
     */
    public function discoverModels(): array
    {
        return [];
    }
}
