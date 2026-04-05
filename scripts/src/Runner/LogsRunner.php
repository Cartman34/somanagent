<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Logs streaming script runner.
 *
 * Streams logs from a Docker container in real time.
 */
final class LogsRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Stream logs from a Docker container in real time';
    }

    protected function getArguments(): array
    {
        return [
            ['name' => '<service>', 'description' => 'Container service name (php, worker, node, db, nginx). Default: php'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--tail', 'description' => 'Number of lines to show from the end'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/logs.php',
            'php scripts/logs.php php',
            'php scripts/logs.php db --tail 50',
        ];
    }

    public function run(array $args): int
    {
        $allowed   = ['php', 'worker', 'node', 'db', 'nginx'];
        $service   = 'php';
        $extraArgs = '';

        foreach ($args as $arg) {
            if (in_array($arg, $allowed, true)) {
                $service = $arg;
            } elseif (str_starts_with($arg, '--')) {
                $extraArgs .= ' ' . escapeshellarg($arg);
            }
        }

        $this->console->info("Streaming logs for service: $service  (Ctrl+C to stop)");

        $code = $this->app->runCommand("docker compose logs -f $extraArgs " . escapeshellarg($service));
        return $code;
    }
}
