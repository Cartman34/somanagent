<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Validate files script runner.
 *
 * Runs targeted backend/frontend validations for an explicit file list.
 */
final class ValidateFilesRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Run targeted backend/frontend validations for an explicit file list';
    }

    protected function getArguments(): array
    {
        return [
            ['name' => '<file> [file...]', 'description' => 'Files to validate'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--with-types', 'description' => 'Also run frontend type checking'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/validate-files.php backend/src/Controller/TaskController.php frontend/src/api/tickets.ts',
            'php scripts/validate-files.php --with-types backend/src/Service/StoryExecutionService.php frontend/src/pages/ProjectDetailPage.tsx',
        ];
    }

    public function run(array $args): int
    {
        $withTypes = false;
        $files = [];

        foreach ($args as $arg) {
            if ($arg === '--with-types') {
                $withTypes = true;
                continue;
            }
            $files[] = $arg;
        }

        if ($files === []) {
            fwrite(STDERR, "Usage: php scripts/validate-files.php [--with-types] <file> [file...]\n");
            return 1;
        }

        $backendPhpFiles = [];
        $frontendLintFiles = [];
        $ignoredFiles = [];
        $needsContainerLint = false;
        $needsDoctrineValidation = false;

        foreach ($files as $file) {
            $normalized = ltrim(str_replace('\\', '/', $file), './');
            if (!file_exists($normalized)) {
                $ignoredFiles[] = $normalized . ' (missing)';
                continue;
            }

            if (str_starts_with($normalized, 'backend/') && str_ends_with($normalized, '.php')) {
                $backendPhpFiles[] = $normalized;

                if (
                    str_starts_with($normalized, 'backend/src/Controller/')
                    || str_starts_with($normalized, 'backend/src/Service/')
                    || str_starts_with($normalized, 'backend/src/Message/')
                    || str_starts_with($normalized, 'backend/src/MessageHandler/')
                    || str_starts_with($normalized, 'backend/src/Command/')
                    || $normalized === 'backend/config/services.yaml'
                ) {
                    $needsContainerLint = true;
                }

                if (
                    str_starts_with($normalized, 'backend/src/Entity/')
                    || str_starts_with($normalized, 'backend/src/Repository/')
                    || str_starts_with($normalized, 'backend/migrations/')
                    || $normalized === 'backend/config/services.yaml'
                    || $normalized === 'backend/config/packages/doctrine.yaml'
                    || $normalized === 'backend/config/packages/doctrine_migrations.yaml'
                ) {
                    $needsDoctrineValidation = true;
                }

                continue;
            }

            if (
                str_starts_with($normalized, 'frontend/src/')
                && preg_match('/\.(ts|tsx|js|jsx)$/', $normalized) === 1
            ) {
                $frontendLintFiles[] = substr($normalized, strlen('frontend/'));
                continue;
            }

            $ignoredFiles[] = $normalized . ' (no targeted validator)';
        }

        $results = [];
        $failed = false;

        $runQuiet = static function (string $command, ?array &$output = null): int {
            $lines = [];
            exec($command . ' 2>&1', $lines, $code);
            $output = $lines;
            return $code;
        };

        $isEnvironmentUnavailable = static function (array $output): bool {
            $joined = mb_strtolower(implode("\n", $output));

            return str_contains($joined, 'permission denied while trying to connect to the docker daemon socket')
                || str_contains($joined, 'cannot connect to the docker daemon')
                || str_contains($joined, 'is the docker daemon running')
                || str_contains($joined, 'service "php" is not running')
                || str_contains($joined, 'service "node" is not running')
                || str_contains($joined, 'no such service');
        };

        if ($backendPhpFiles !== []) {
            $syntaxFailures = [];

            foreach ($backendPhpFiles as $file) {
                $output = [];
                $code = $runQuiet('php -l ' . escapeshellarg($file), $output);
                if ($code !== 0) {
                    $syntaxFailures[] = [$file, $output];
                }
            }

            if ($syntaxFailures === []) {
                $results[] = sprintf('PHP syntax: OK (%d file%s)', count($backendPhpFiles), count($backendPhpFiles) > 1 ? 's' : '');
            } else {
                $failed = true;
                $results[] = sprintf('PHP syntax: FAIL (%d/%d)', count($syntaxFailures), count($backendPhpFiles));
                foreach ($syntaxFailures as [$file, $output]) {
                    $results[] = '  - ' . $file;
                    foreach (array_slice($output, -3) as $line) {
                        $results[] = '    ' . $line;
                    }
                }
            }
        } else {
            $results[] = 'PHP syntax: SKIP';
        }

        if ($needsContainerLint) {
            $output = [];
            $code = $runQuiet('php scripts/console.php lint:container --no-interaction', $output);
            if ($code === 0) {
                $results[] = 'Symfony container: OK';
            } elseif ($isEnvironmentUnavailable($output)) {
                $results[] = 'Symfony container: UNAVAILABLE';
            } else {
                $failed = true;
                $results[] = 'Symfony container: FAIL';
                foreach (array_slice($output, -8) as $line) {
                    $results[] = '  ' . $line;
                }
            }
        } else {
            $results[] = 'Symfony container: SKIP';
        }

        if ($needsDoctrineValidation) {
            $output = [];
            $code = $runQuiet('php scripts/console.php doctrine:schema:validate --no-interaction', $output);
            if ($code === 0) {
                $results[] = 'Doctrine schema: OK';
            } elseif ($isEnvironmentUnavailable($output)) {
                $results[] = 'Doctrine schema: UNAVAILABLE';
            } else {
                $failed = true;
                $results[] = 'Doctrine schema: FAIL';
                foreach (array_slice($output, -12) as $line) {
                    $results[] = '  ' . $line;
                }
            }
        } else {
            $results[] = 'Doctrine schema: SKIP';
        }

        if ($frontendLintFiles !== []) {
            $command = 'php scripts/node.php exec ./node_modules/.bin/eslint --max-warnings 0 '
                . implode(' ', array_map('escapeshellarg', $frontendLintFiles));
            $output = [];
            $code = $runQuiet($command, $output);
            if ($code === 0) {
                $results[] = sprintf('Frontend lint: OK (%d file%s)', count($frontendLintFiles), count($frontendLintFiles) > 1 ? 's' : '');
            } elseif ($isEnvironmentUnavailable($output)) {
                $results[] = sprintf('Frontend lint: UNAVAILABLE (%d file%s)', count($frontendLintFiles), count($frontendLintFiles) > 1 ? 's' : '');
            } else {
                $failed = true;
                $results[] = sprintf('Frontend lint: FAIL (%d file%s)', count($frontendLintFiles), count($frontendLintFiles) > 1 ? 's' : '');
                foreach (array_slice($output, -12) as $line) {
                    $results[] = '  ' . $line;
                }
            }
        } else {
            $results[] = 'Frontend lint: SKIP';
        }

        if ($withTypes && $frontendLintFiles !== []) {
            $output = [];
            $code = $runQuiet('php scripts/node.php type-check', $output);
            if ($code === 0) {
                $results[] = 'Frontend type-check: OK';
            } elseif ($isEnvironmentUnavailable($output)) {
                $results[] = 'Frontend type-check: UNAVAILABLE';
            } else {
                $failed = true;
                $results[] = 'Frontend type-check: FAIL';
                foreach (array_slice($output, -12) as $line) {
                    $results[] = '  ' . $line;
                }
            }
        } else {
            $results[] = 'Frontend type-check: SKIP';
        }

        if ($ignoredFiles !== []) {
            $results[] = sprintf('Ignored: %d', count($ignoredFiles));
            foreach ($ignoredFiles as $ignored) {
                $results[] = '  - ' . $ignored;
            }
        }

        foreach ($results as $line) {
            echo $line . PHP_EOL;
        }

        return $failed ? 1 : 0;
    }
}
