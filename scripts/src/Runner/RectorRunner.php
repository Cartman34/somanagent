<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Rector script runner.
 *
 * Applies automated code fixes to backend and/or scripts PHP sources.
 */
final class RectorRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Apply automated code fixes to backend and/or scripts PHP sources via Rector';
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--dry-run', 'description' => 'Show what would be changed without applying fixes'],
            ['name' => '--backend', 'description' => 'Process backend only'],
            ['name' => '--scripts', 'description' => 'Process scripts only'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/rector.php',
            'php scripts/rector.php --dry-run',
            'php scripts/rector.php --backend',
            'php scripts/rector.php --scripts --dry-run',
        ];
    }

    /**
     * Runs Rector process on backend and/or scripts sources.
     * By default both scopes are processed; use --backend or --scripts to restrict.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        $backendOnly = in_array('--backend', $args, true);
        $scriptsOnly = in_array('--scripts', $args, true);
        $passthrough = array_values(array_filter($args, static fn(string $a) => !in_array($a, ['--backend', '--scripts'], true)));

        $paths = [];

        if ($backendOnly || (!$backendOnly && !$scriptsOnly)) {
            $paths[] = 'backend/src';
            $paths[] = 'backend/tests';
        }

        if ($scriptsOnly || (!$backendOnly && !$scriptsOnly)) {
            $paths[] = 'scripts/src';
        }

        $commandArgs = array_merge(
            ['process', '--config', 'config/rector.php', '--paths', implode(',', $paths)],
            $passthrough
        );

        $escaped = implode(' ', array_map('escapeshellarg', $commandArgs));

        return $this->app->runCommand("php scripts/vendor/bin/rector $escaped");
    }
}
