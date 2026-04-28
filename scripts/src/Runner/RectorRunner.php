<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Rector script runner.
 *
 * Applies automated code fixes to backend PHP sources using the project Rector configuration.
 */
final class RectorRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Apply automated code fixes to backend PHP sources via Rector';
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--dry-run', 'description' => 'Show what would be changed without applying fixes'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/rector.php',
            'php scripts/rector.php --dry-run',
        ];
    }

    /**
     * Runs Rector process with the backend project configuration.
     * Always injects --config backend/rector.php; passes through any extra arguments.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        $commandArgs = array_merge(
            ['process', '--config', 'backend/rector.php'],
            $args
        );

        $escaped = implode(' ', array_map('escapeshellarg', $commandArgs));

        return $this->app->runCommand("php backend/vendor/bin/rector $escaped");
    }
}
