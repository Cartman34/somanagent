<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Development environment script runner.
 *
 * Starts or stops the Docker Compose development environment.
 */
final class DevRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Start or stop the development environment (Docker Compose)';
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--stop', 'description' => 'Stop the development environment'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/dev.php',
            'php scripts/dev.php --stop',
        ];
    }

    public function run(array $args): int
    {
        $stop = in_array('--stop', $args, true);

        if ($stop) {
            $this->console->step('Stopping containers');
            $code = $this->app->runCommand('docker compose down');
            if ($code !== 0) {
                throw new \RuntimeException("docker compose down failed (exit $code).");
            }
            $this->console->ok('Containers stopped.');
            return 0;
        }

        $this->console->step('Starting SoManAgent');
        $code = $this->app->runCommand('docker compose up -d');
        if ($code !== 0) {
            throw new \RuntimeException("docker compose up failed (exit $code).");
        }

        $this->console->line();
        $this->console->line('  API  →  http://localhost:8080/api/health');
        $this->console->line('  UI   →  http://localhost:5173');
        $this->console->line('  DB   →  localhost:5432  (somanagent / somanagent)');
        $this->console->line();
        $this->console->line('  Logs  : php scripts/logs.php [php|worker|node|db|nginx]');
        $this->console->line('  Stop  : php scripts/dev.php --stop');
        $this->console->line();

        return 0;
    }
}
