<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

/**
 * PHPUnit script runner.
 *
 * Runs PHPUnit on the project scopes.
 */
final class PhpUnitRunner extends AbstractScriptRunner
{
    private const SCOPE_BACKEND = 'backend';

    /**
     * @var array<string, array{bin: string, config: string}>
     */
    private const SCOPES = [
        self::SCOPE_BACKEND => [
            'bin' => 'backend/vendor/bin/phpunit',
            'config' => 'backend/phpunit.dist.xml',
        ],
    ];

    private const NAME = 'phpunit';

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function getDescription(): string
    {
        return 'Run PHPUnit on the project scopes';
    }

    protected function getArguments(): array
    {
        return [
            ['name' => '[file...]', 'description' => 'Optional files to test instead of the full project'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--scope', 'description' => 'Test one scope; repeat --scope=backend for multiple scopes'],
            ['name' => '--suite', 'description' => 'Run a specific test suite (e.g. local-unit)'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/phpunit.php',
            'php scripts/phpunit.php --scope=backend',
            'php scripts/phpunit.php --suite local-unit',
            'php scripts/phpunit.php backend/tests/Unit/Service/MyServiceTest.php',
        ];
    }

    /**
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        [$files, $options] = $this->parseArgs(array_values($args));
        
        $testsuite = $options['suite'] ?? null;
        if (is_array($testsuite)) {
            throw new \RuntimeException('Option --suite cannot be used multiple times.');
        }
        if (is_bool($testsuite)) {
            throw new \RuntimeException('Option --suite requires a value.');
        }

        $scopeOptions = [];
        if (isset($options['scope'])) {
            $rawScope = $options['scope'];
            if (is_string($rawScope)) {
                $scopeOptions = [$rawScope];
            } elseif (is_array($rawScope)) {
                $scopeOptions = $rawScope;
            } else {
                throw new \RuntimeException('Option --scope requires a value.');
            }
        }

        // Rule 1A: Strict exclusion between files and --scope
        if ($files !== [] && $scopeOptions !== []) {
            throw new \RuntimeException('Files determine their own scope, remove --scope option.');
        }

        if ($files !== []) {
            $filesByScope = $this->groupFilesByScope($files);
            
            if ($filesByScope === []) {
                return 1;
            }

            $exitCode = 0;
            foreach ($filesByScope as $scope => $scopeFiles) {
                $code = $this->runScope($scope, (string) $testsuite, $scopeFiles);
                if ($code !== 0) {
                    $exitCode = $code;
                }
            }
            return $exitCode;
        }

        $scopes = $scopeOptions;
        if ($scopes === []) {
            $scopes = array_keys(self::SCOPES);
        } else {
            foreach ($scopes as $scope) {
                if (!isset(self::SCOPES[$scope])) {
                    throw new \RuntimeException(sprintf('Unknown scope "%s". Available scopes: %s.', (string) $scope, implode(', ', array_keys(self::SCOPES))));
                }
            }
            $scopes = array_values(array_unique($scopes));
        }

        $exitCode = 0;
        foreach ($scopes as $scope) {
            $code = $this->runScope((string) $scope, (string) $testsuite, []);
            if ($code !== 0) {
                $exitCode = $code;
            }
        }

        return $exitCode;
    }

    /**
     * @param array<string> $files
     * @return array<string, array<string>>
     */
    private function groupFilesByScope(array $files): array
    {
        $grouped = [];
        $validFiles = false;

        foreach ($files as $file) {
            // Normalize path relative to project root
            $normalizedPath = $file;
            $absolutePath = $this->projectRoot . '/' . ltrim($file, '/');

            if (str_starts_with($file, '/')) {
                if (str_starts_with($file, $this->projectRoot . '/')) {
                    $normalizedPath = substr($file, strlen($this->projectRoot . '/'));
                    $absolutePath = $file;
                }
            }

            if (!is_file($absolutePath) && !is_dir($absolutePath)) {
                echo sprintf("Warning: File or directory not found: \"%s\"\n", $file);
                continue;
            }

            $matched = false;
            foreach (array_keys(self::SCOPES) as $scope) {
                if (str_starts_with($normalizedPath, $scope . '/')) {
                    $grouped[$scope][] = $normalizedPath;
                    $matched = true;
                    $validFiles = true;
                    break;
                }
            }

            if (!$matched) {
                echo sprintf("Warning: Could not determine scope for file \"%s\"\n", $file);
            }
        }

        return $validFiles ? $grouped : [];
    }

    /**
     * @param string[] $scopeFiles
     */
    private function runScope(string $scope, ?string $testsuite, array $scopeFiles): int
    {
        $config = self::SCOPES[$scope];
        $commandParts = [];
        
        $commandParts[] = 'php';
        $commandParts[] = escapeshellarg($config['bin']);
        $commandParts[] = '--configuration';
        $commandParts[] = escapeshellarg($config['config']);
        
        if ($testsuite !== null && $testsuite !== '') {
            $commandParts[] = '--testsuite';
            $commandParts[] = escapeshellarg($testsuite);
        }
        
        foreach ($scopeFiles as $file) {
            $commandParts[] = escapeshellarg($file);
        }

        return $this->app->runCommand(implode(' ', $commandParts));
    }
}
