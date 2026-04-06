<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Adapter\AI;

use App\Enum\ConnectorType;
use App\Port\AgentPort;
use App\ValueObject\AgentConfig;
use App\ValueObject\AgentResponse;
use App\ValueObject\Prompt;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * AgentPort implementation using the Claude CLI (local process execution).
 */
class ClaudeCliAdapter implements AgentPort
{
    /**
     * Executes the Claude CLI, parses its output, and normalizes the resulting response.
     */
    public function sendPrompt(Prompt $prompt, AgentConfig $config): AgentResponse
    {
        $start = microtime(true);

        $process = new Process(
            command: [
                'sh',
                '-lc',
                sprintf(
                    'HOME=/claude-home claude --print --no-session-persistence --output-format json --model %s',
                    escapeshellarg($config->model),
                ),
            ],
            cwd: '/var/www/backend',
            timeout: $config->timeout,
        );

        // Pass prompt via stdin — avoids argument length limits and shell escaping issues
        $process->setInput($prompt->build());
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

            return AgentResponse::fromCliJson(
                content: $content,
                durationMs: $durationMs,
                usage: $usage,
                metadata: [
                    'source' => 'cli',
                    'stop_reason' => $decoded['stop_reason'] ?? null,
                    'session_id' => $decoded['session_id'] ?? null,
                ],
            );
        }

        return AgentResponse::fromCli($output, $durationMs);
    }

    /**
     * Checks whether the Claude CLI executable is available in the runtime environment.
     */
    public function healthCheck(): bool
    {
        $process = new Process(
            ['sh', '-lc', 'HOME=/claude-home claude --version'],
            cwd: '/var/www/backend',
            timeout: 5,
        );
        $process->run();
        return $process->isSuccessful();
    }

    /**
     * Indicates whether this adapter handles the Claude CLI connector type.
     */
    public function supportsConnector(ConnectorType $type): bool
    {
        return $type === ConnectorType::ClaudeCli;
    }
}
