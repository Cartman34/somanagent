<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Review script runner.
 *
 * Runs mechanical review checks on modified and untracked files.
 */
final class ReviewRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Run mechanical review checks on modified and untracked files';
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/review.php',
        ];
    }

    public function run(array $args): int
    {
        $run = static function (string $command): array {
            $lines = [];
            exec($command . ' 2>&1', $lines, $code);
            return [$code, $lines];
        };

        $readLines = static function (string $path): array {
            $content = file_get_contents($path);
            if ($content === false) {
                return [];
            }
            return explode("\n", $content);
        };

        $hasDocBlock = static function (array $lines, int $i): bool {
            for ($j = $i - 1; $j >= 0; $j--) {
                $prev = trim($lines[$j]);
                if ($prev === '') {
                    return false;
                }
                if ($prev === '*/' || (str_starts_with($prev, '/**') && str_ends_with($prev, '*/'))) {
                    return true;
                }
                if (str_starts_with($prev, '//') || str_starts_with($prev, '*') || str_starts_with($prev, '@') || str_starts_with($prev, '#[')) {
                    continue;
                }
                return false;
            }
            return false;
        };

        [, $statusLines] = $run('git status --porcelain');

        $modifiedFiles  = [];
        $untrackedFiles = [];

        foreach ($statusLines as $line) {
            if (strlen($line) < 4) {
                continue;
            }
            $xy   = substr($line, 0, 2);
            $path = trim(substr($line, 3));

            if (str_contains($path, ' -> ')) {
                $path = substr($path, strrpos($path, ' -> ') + 4);
            }
            $path = trim($path, '"');

            if ($xy === '??') {
                $untrackedFiles[] = $path;
            } elseif (str_contains($xy, 'M') || str_contains($xy, 'A')) {
                $modifiedFiles[] = $path;
            }
        }

        $allFiles = array_filter(
            array_merge($modifiedFiles, $untrackedFiles),
            static fn(string $p) => !str_ends_with($p, '/') && file_exists($p)
        );

        echo "=== Modified files ===\n";
        if ($modifiedFiles === []) {
            echo "(none)\n";
        } else {
            foreach ($modifiedFiles as $f) {
                echo "M  $f\n";
            }
        }
        echo "\n";

        echo "=== Untracked files ===\n";
        if ($untrackedFiles === []) {
            echo "(none)\n";
        } else {
            foreach ($untrackedFiles as $path) {
                echo "?? $path\n";
            }
        }
        echo "\n";

        echo "=== French strings in modified/new source files ===\n";

        $frenchSourceFiles = array_filter($allFiles, static function (string $path): bool {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if ($ext === 'php') {
                return str_starts_with($path, 'backend/src/');
            }
            if ($ext === 'ts' || $ext === 'tsx') {
                return str_starts_with($path, 'frontend/src/');
            }
            return false;
        });

        $frenchRegex = '/[\x{00C0}-\x{00FF}\x{0152}\x{0153}]/u';

        $frenchHits = [];
        foreach ($frenchSourceFiles as $path) {
            $lines = $readLines($path);
            foreach ($lines as $lineNum => $lineContent) {
                if (preg_match($frenchRegex, $lineContent) === 1) {
                    $frenchHits[] = sprintf('%s:%d  %s', $path, $lineNum + 1, trim($lineContent));
                }
            }
        }

        if ($frenchHits === []) {
            echo "(none)\n";
        } else {
            foreach ($frenchHits as $hit) {
                echo $hit . "\n";
            }
        }
        echo "\n";

        echo "=== Missing PHPDoc on public PHP methods (backend/src/) ===\n";

        $phpFiles = array_filter($allFiles, static function (string $path): bool {
            return pathinfo($path, PATHINFO_EXTENSION) === 'php'
                && str_starts_with($path, 'backend/src/');
        });

        $missingPhpdoc = [];

        foreach ($phpFiles as $path) {
            $lines     = $readLines($path);
            $lineCount = count($lines);

            for ($i = 0; $i < $lineCount; $i++) {
                $line = $lines[$i];

                if (!preg_match('/^\s*public\s+(static\s+|abstract\s+|abstract\s+static\s+)?function\s+\w+/', $line)) {
                    continue;
                }

                if (!$hasDocBlock($lines, $i)) {
                    $missingPhpdoc[] = sprintf('%s:%d  %s', $path, $i + 1, trim($line));
                }
            }
        }

        if ($missingPhpdoc === []) {
            echo "(none)\n";
        } else {
            foreach ($missingPhpdoc as $hit) {
                echo $hit . "\n";
            }
        }
        echo "\n";

        echo "=== Missing JSDoc on exported declarations (frontend/src/ .ts/.tsx) ===\n";

        $tsFiles = array_filter($allFiles, static function (string $path): bool {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            return ($ext === 'ts' || $ext === 'tsx') && str_starts_with($path, 'frontend/src/');
        });

        $missingJsdoc = [];

        foreach ($tsFiles as $path) {
            $lines     = $readLines($path);
            $lineCount = count($lines);

            for ($i = 0; $i < $lineCount; $i++) {
                $line = $lines[$i];

                if (!preg_match('/^\s*export\s+(default\s+)?(function|class|const|type|interface)\s+\w+/', $line)) {
                    continue;
                }

                if (!$hasDocBlock($lines, $i)) {
                    $missingJsdoc[] = sprintf('%s:%d  %s', $path, $i + 1, trim($line));
                }
            }
        }

        if ($missingJsdoc === []) {
            echo "(none)\n";
        } else {
            foreach ($missingJsdoc as $hit) {
                echo $hit . "\n";
            }
        }
        echo "\n";

        echo "=== File validation ===\n";

        $validateFiles = array_values($allFiles);
        $validateExitCode = 0;

        if ($validateFiles === []) {
            echo "(no files to validate)\n";
        } else {
            $fileArgs = implode(' ', array_map('escapeshellarg', $validateFiles));
            [$validateExitCode, $validateLines] = $run('php scripts/validate-files.php --with-types ' . $fileArgs);
            foreach ($validateLines as $line) {
                echo $line . "\n";
            }
        }
        echo "\n";

        echo "=== Translation validation ===\n";
        [$translationExitCode, $translationLines] = $run('php scripts/validate-translations.php');
        foreach ($translationLines as $line) {
            echo $line . "\n";
        }
        echo "\n";

        $hasBlockers = $frenchHits !== [] || $missingPhpdoc !== [] || $missingJsdoc !== []
            || $validateExitCode !== 0
            || $translationExitCode !== 0;
        return $hasBlockers ? 1 : 0;
    }
}
