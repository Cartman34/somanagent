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
    public const NAME = 'claude-auth';

    protected function getName(): string
    {
        return self::NAME;
    }

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
        [$positional, $options] = $this->parseArgs(array_values($args));

        $command = $positional[0] ?? 'status';
        $force   = isset($options['force']);
        $baseUrl = $this->getSingleOption($options, 'url', 'http://localhost:8080');
        $model   = $this->getSingleOption($options, 'model', '');

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
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function getSingleOption(array $options, string $name, string $default): string
    {
        $val = $options[$name] ?? $default;
        if (is_array($val)) {
            throw new \RuntimeException(sprintf('Option --%s cannot be repeated.', $name));
        }
        if (is_bool($val)) {
            throw new \RuntimeException(sprintf('Option --%s requires a value.', $name));
        }

        return (string) $val;
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

        if ($data['success'] ?? false) {
            $this->console->ok('Claude CLI reachable via API');
            $this->console->line('Response: ' . $data['response']);
            return 0;
        }

        $this->console->line('  ❌ Test failed: ' . ($data['error'] ?? 'unknown error'));
        return 1;
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
