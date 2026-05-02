<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * PHPStan script runner.
 *
 * Runs PHPStan static analysis on backend and/or scripts PHP sources.
 */
final class PhpstanRunner extends AbstractScriptRunner
{
    public const NAME = 'phpstan';

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
        return 'Run PHPStan static analysis on backend and/or scripts PHP sources';
    }

    protected function getArguments(): array
    {
        return [
            ['name' => '[file...]', 'description' => 'Optional files to analyse instead of the full project'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--scope', 'description' => 'Analyse one scope; repeat --scope=backend --scope=scripts for multiple scopes'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/phpstan.php',
            'php scripts/phpstan.php --scope=backend',
            'php scripts/phpstan.php --scope=scripts',
            'php scripts/phpstan.php backend/src/Controller/AgentController.php',
        ];
    }

    /**
     * Runs PHPStan analyse on backend and/or scripts sources.
     * By default all configured scopes are analysed; use --scope to restrict.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        $files = array_values(array_filter(
            $args,
            static fn(string $a) => !str_starts_with($a, '--scope=')
        ));

        if ($files !== []) {
            return $this->analyse($files);
        }

        $paths = [];
        foreach ($this->resolveScopes($args) as $scope) {
            array_push($paths, ...self::SCOPES[$scope]['paths']);
        }

        return $this->analyse($paths);
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

    /**
     * @param list<string> $paths
     */
    private function analyse(array $paths): int
    {
        // --debug forces single-threaded mode, required on WSL2 where parallel worker IPC fails
        $commandArgs = array_merge(
            ['analyse', '--configuration', 'config/phpstan.neon', '--debug'],
            $paths
        );

        $escaped = implode(' ', array_map('escapeshellarg', $commandArgs));

        return $this->app->runCommand("php scripts/vendor/bin/phpstan $escaped");
    }
}
