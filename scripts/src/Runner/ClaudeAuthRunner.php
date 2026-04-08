<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\ClaudeAuthManager;

/**
 * Claude auth management script runner.
 *
 * Manages Claude CLI auth with WSL as the source of truth and syncs it to Docker.
 */
final class ClaudeAuthRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Manage Claude CLI auth with WSL as the source of truth and sync it to Docker';
    }

    protected function getCommands(): array
    {
        return [
            ['name' => 'status', 'description' => 'Show current auth status'],
            ['name' => 'sync', 'description' => 'Sync auth from WSL to Docker'],
            ['name' => 'login', 'description' => 'Login and sync'],
            ['name' => 'test', 'description' => 'Send a minimal test prompt via the API (tests the FPM execution path)'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--force', 'description' => 'Force overwrite existing auth (sync) or re-authenticate (login)'],
            ['name' => '--url', 'description' => 'Base URL of the API (default: http://localhost:8080)'],
            ['name' => '--model', 'description' => 'Model override for the test command'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/claude-auth.php status',
            'php scripts/claude-auth.php sync',
            'php scripts/claude-auth.php sync --force',
            'php scripts/claude-auth.php login',
            'php scripts/claude-auth.php login --force',
            'php scripts/claude-auth.php test',
            'php scripts/claude-auth.php test --url http://localhost:8080',
            'php scripts/claude-auth.php test --model claude-haiku-4-5-20251001',
        ];
    }

    /**
     * Dispatches the requested Claude auth action to the manager, or runs the API test for the `test` command.
     */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'status';
        $force   = in_array('--force', $args, true);
        $baseUrl = $this->parseOption($args, '--url', 'http://localhost:8080');
        $model   = $this->parseOption($args, '--model', '');

        if ($command === 'test') {
            return $this->runTest($baseUrl, $model !== '' ? $model : null);
        }

        try {
            $manager = new ClaudeAuthManager($this->app, $this->projectRoot);

            match ($command) {
                'status' => $manager->showStatus(),
                'sync'   => $manager->sync($force),
                'login'  => $manager->loginAndSync($force),
                default  => throw new \RuntimeException(sprintf('Unknown command "%s". Use status, sync, login, or test.', $command)),
            };
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        }

        return 0;
    }

    /**
     * Sends a minimal test prompt through the API connector test endpoint and prints the result.
     *
     * This exercises the FPM execution path (Nginx → PHP-FPM → ClaudeCliConnector)
     * as opposed to somanagent:connector:test which runs directly in the CLI process.
     */
    private function runTest(string $baseUrl, ?string $model): int
    {
        $this->console->step('Testing Claude CLI via FPM API');
        $this->console->info(sprintf('Endpoint: %s/api/health/connectors/claude_cli/test', $baseUrl));

        $url = $baseUrl . '/api/health/connectors/claude_cli/test';
        if ($model !== null) {
            $url .= '?model=' . urlencode($model);
        }

        try {
            $data = $this->httpPost($url);
        } catch (\RuntimeException $e) {
            $this->console->line('  ❌ API unreachable: ' . $e->getMessage());
            $this->console->line('  → Start the stack with: php scripts/dev.php');
            return 1;
        }

        $ok = (bool) ($data['ok'] ?? false);

        if ($ok) {
            $this->console->ok(sprintf(
                'Response received in %d ms (model: %s)',
                (int) ($data['durationMs'] ?? 0),
                $data['model'] ?? '?',
            ));
            $this->console->info(sprintf(
                'Tokens: %d in / %d out',
                (int) ($data['inputTokens'] ?? 0),
                (int) ($data['outputTokens'] ?? 0),
            ));
            $this->console->info('Response: ' . ($data['response'] ?? '(empty)'));
            return 0;
        }

        $this->console->line('  ❌ Test failed: ' . ($data['error'] ?? 'unknown error'));
        return 1;
    }

    /**
     * Parses a named option from CLI arguments and returns its value or the given default.
     *
     * @param array<string> $args
     */
    private function parseOption(array $args, string $name, string $default): string
    {
        foreach ($args as $i => $arg) {
            if ($arg === $name && isset($args[$i + 1])) {
                return $args[$i + 1];
            }
            if (str_starts_with($arg, $name . '=')) {
                return substr($arg, strlen($name) + 1);
            }
        }
        return $default;
    }

    /**
     * Performs a POST request and decodes the JSON response.
     *
     * @return array<mixed>
     * @throws \RuntimeException when the request fails or returns non-JSON.
     */
    private function httpPost(string $url): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'timeout'       => 45,
                'ignore_errors' => true,
                'header'        => "Content-Type: application/json\r\nContent-Length: 0\r\n",
                'content'       => '',
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);

        if ($raw === false) {
            throw new \RuntimeException("Cannot reach $url");
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new \RuntimeException("Non-JSON response from $url");
        }

        return $data;
    }
}
