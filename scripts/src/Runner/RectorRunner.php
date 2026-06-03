<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

use Sowapps\SoManAgent\Script\Runner\AbstractScriptRunner;

/**
 * Rector script runner.
 *
 * Applies automated code fixes to backend and/or scripts PHP sources.
 */
final class RectorRunner extends AbstractScriptRunner
{
    private const NAME = 'rector';

    protected function getName(): string
    {
        return self::NAME;
    }

    private const SCOPE_BACKEND = 'backend';
    private const SCOPE_SCRIPTS = 'scripts';

    /** @var array<string, array{paths: list<string>}> */
    private const SCOPES = [
        self::SCOPE_BACKEND => ['paths' => ['backend/src', 'backend/tests']],
        self::SCOPE_SCRIPTS => ['paths' => ['scripts/src']],
    ];

    protected function getDescription(): string
    {
        return 'Apply automated code fixes to backend and/or scripts PHP sources via Rector';
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--dry-run', 'description' => 'Show what would be changed without applying fixes'],
            ['name' => '--scope', 'description' => 'Process one scope; repeat --scope=backend --scope=scripts for multiple scopes'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/rector.php',
            'php scripts/rector.php --dry-run',
            'php scripts/rector.php --scope=backend',
            'php scripts/rector.php --scope=scripts --dry-run',
        ];
    }

    /**
     * Runs Rector process on backend and/or scripts sources.
     * By default all configured scopes are processed; use --scope to restrict.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        $passthrough = array_values(array_filter(
            $args,
            static fn(string $a) => !str_starts_with($a, '--scope=')
        ));

        $paths = [];
        foreach ($this->resolveScopes($args) as $scope) {
            array_push($paths, ...self::SCOPES[$scope]['paths']);
        }

        $commandArgs = array_merge(
            ['process', '--config', 'config/rector.php'],
            $paths,
            $passthrough
        );

        $escaped = implode(' ', array_map('escapeshellarg', $commandArgs));

        return $this->app->runCommand("php scripts/vendor/bin/rector $escaped");
    }

    /**
     * @param array<string> $args
     * @return list<string>
     */
    private function resolveScopes(array $args): array
    {
        $scopeOptions = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--scope=')) {
                $scopeOptions[] = substr($arg, strlen('--scope='));
            }
        }

        $scopes = $scopeOptions;
        if ($scopes === []) {
            $scopes = array_keys(self::SCOPES);
        }

        foreach ($scopes as $scope) {
            if (!isset(self::SCOPES[$scope])) {
                throw new \RuntimeException(sprintf('Unknown scope "%s". Available scopes: %s.', $scope, implode(', ', array_keys(self::SCOPES))));
            }
        }

        return array_values(array_unique($scopes));
    }
}
