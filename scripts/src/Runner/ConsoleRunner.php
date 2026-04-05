<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Console script runner.
 *
 * Runs a Symfony bin/console command inside the PHP Docker container.
 */
final class ConsoleRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Run a Symfony bin/console command inside the PHP Docker container';
    }

    protected function getArguments(): array
    {
        return [
            ['name' => '<command> [args...]', 'description' => 'Symfony console command and its arguments'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/console.php cache:clear',
            'php scripts/console.php doctrine:migrations:migrate --no-interaction',
        ];
    }

    public function run(array $args): int
    {
        if ($args === []) {
            $this->console->line('Usage: php scripts/console.php <command> [args...]');
            $this->console->line('Ex:    php scripts/console.php doctrine:migrations:migrate --no-interaction');
            return 1;
        }

        $app = $this->app;
        $escapedArgs = implode(' ', array_map('escapeshellarg', $args));
        return $app->runCommand("docker compose exec -T php php bin/console $escapedArgs");
    }
}
