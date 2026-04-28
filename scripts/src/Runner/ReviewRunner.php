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
    private const FRENCH_REGEX = '/[\x{00C0}-\x{00FF}\x{0152}\x{0153}]/u';

    protected function getDescription(): string
    {
        return 'Run mechanical review checks on modified and untracked files';
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/review.php',
            'php scripts/review.php --base=HEAD~1',
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--base', 'description' => 'Also review files changed between this git base ref and HEAD'],
        ];
    }

    /**
     * Executes the review checks against modified and untracked files.
     */
    public function run(array $args): int
    {
        $base = $this->parseBaseOption($args);
        [$modifiedFiles, $untrackedFiles] = $this->collectGitStatusFiles();
        $committedFiles = $base !== null ? $this->collectCommittedFiles($base) : [];
        $allFiles = $this->filterExistingFiles(array_values(array_unique(array_merge($modifiedFiles, $untrackedFiles, $committedFiles))));
        $phpFiles = $this->filterPhpSourceFiles($allFiles);
        $tsFiles = $this->filterFrontendSourceFiles($allFiles);

        echo "=== Modified files ===\n";
        $this->printPrefixedList($modifiedFiles, 'M  ');
        echo "\n";

        echo "=== Untracked files ===\n";
        $this->printPrefixedList($untrackedFiles, '?? ');
        echo "\n";

        if ($base !== null) {
            echo "=== Committed files since {$base} ===\n";
            $this->printPrefixedList($committedFiles, 'C  ');
            echo "\n";
        }

        $frenchHits = $this->collectFrenchStringHits($allFiles);
        echo "=== French strings in modified/new source files ===\n";
        $this->printList($frenchHits);
        echo "\n";

        $missingClassPhpdoc = $this->collectMissingClassPhpdoc($phpFiles);
        echo "=== Missing PHPDoc on PHP classes, enums, and interfaces (backend/src/ + scripts/src/) ===\n";
        $this->printList($missingClassPhpdoc);
        echo "\n";

        $missingPhpdoc = $this->collectMissingPublicMethodPhpdoc($phpFiles);
        echo "=== Missing PHPDoc on public PHP methods (backend/src/ + scripts/src/) ===\n";
        $this->printList($missingPhpdoc);
        echo "\n";

        $missingJsdoc = $this->collectMissingJsdoc($tsFiles);
        echo "=== Missing JSDoc on exported declarations (frontend/src/ .ts/.tsx) ===\n";
        $this->printList($missingJsdoc);
        echo "\n";

        echo "=== File validation ===\n";
        $validateExitCode = $this->printFileValidation($allFiles);
        echo "\n";

        echo "=== Translation validation ===\n";
        $translationExitCode = $this->printTranslationValidation();
        echo "\n";

        echo "=== Backend service PHPUnit validation ===\n";
        $backendTestsExitCode = $this->printBackendTestsValidation($allFiles);
        echo "\n";

        $hasBlockers = $frenchHits !== [] || $missingPhpdoc !== [] || $missingJsdoc !== []
            || $validateExitCode !== 0
            || $translationExitCode !== 0
            || $backendTestsExitCode !== 0;

        return $hasBlockers ? 1 : 0;
    }

    /**
     * @param array<string> $args
     */
    private function parseBaseOption(array $args): ?string
    {
        $base = null;

        while ($args !== []) {
            $arg = array_shift($args);
            if ($arg === null) {
                continue;
            }

            if (str_starts_with($arg, '--base=')) {
                $base = substr($arg, strlen('--base='));
                continue;
            }

            if ($arg === '--base') {
                $base = (string) array_shift($args);
                continue;
            }

            throw new \RuntimeException("Unknown review argument: {$arg}");
        }

        $base = trim((string) $base);

        return $base !== '' ? $base : null;
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function collectGitStatusFiles(): array
    {
        [, $statusLines] = $this->runCommand('git status --porcelain');

        $modifiedFiles = [];
        $untrackedFiles = [];

        foreach ($statusLines as $line) {
            if (strlen($line) < 4) {
                continue;
            }

            $xy = substr($line, 0, 2);
            $path = trim(substr($line, 3));

            if (str_contains($path, ' -> ')) {
                $path = substr($path, strrpos($path, ' -> ') + 4);
            }

            $path = trim($path, '"');

            if ($xy === '??') {
                $untrackedFiles[] = $path;
                continue;
            }

            if (str_contains($xy, 'M') || str_contains($xy, 'A')) {
                $modifiedFiles[] = $path;
            }
        }

        return [$modifiedFiles, $untrackedFiles];
    }

    /**
     * @return list<string>
     */
    private function collectCommittedFiles(string $base): array
    {
        [$exitCode, $lines] = $this->runCommand(sprintf(
            'git diff --name-only --diff-filter=ACMR %s..HEAD',
            escapeshellarg($base),
        ));
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf('Unable to collect committed files since base %s.', $base));
        }

        return array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
    }

    /**
     * @param string[] $files
     * @return string[]
     */
    private function filterExistingFiles(array $files): array
    {
        return array_values(array_filter(
            $files,
            static fn(string $path): bool => !str_ends_with($path, '/') && file_exists($path)
        ));
    }

    /**
     * @param string[] $files
     * @return string[]
     */
    private function filterPhpSourceFiles(array $files): array
    {
        return array_values(array_filter($files, static function (string $path): bool {
            return pathinfo($path, PATHINFO_EXTENSION) === 'php'
                && (str_starts_with($path, 'backend/src/') || str_starts_with($path, 'scripts/src/'));
        }));
    }

    /**
     * @param string[] $files
     * @return string[]
     */
    private function filterFrontendSourceFiles(array $files): array
    {
        return array_values(array_filter($files, static function (string $path): bool {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            return ($ext === 'ts' || $ext === 'tsx') && str_starts_with($path, 'frontend/src/');
        }));
    }

    /**
     * @param string[] $files
     * @return string[]
     */
    private function collectFrenchStringHits(array $files): array
    {
        $hits = [];

        foreach ($this->filterPhpSourceFiles($files) as $path) {
            foreach ($this->readLines($path) as $lineNum => $lineContent) {
                if (preg_match(self::FRENCH_REGEX, $lineContent) === 1) {
                    $hits[] = sprintf('%s:%d  %s', $path, $lineNum + 1, trim($lineContent));
                }
            }
        }

        foreach ($this->filterFrontendSourceFiles($files) as $path) {
            foreach ($this->readLines($path) as $lineNum => $lineContent) {
                if (preg_match(self::FRENCH_REGEX, $lineContent) === 1) {
                    $hits[] = sprintf('%s:%d  %s', $path, $lineNum + 1, trim($lineContent));
                }
            }
        }

        return $hits;
    }

    /**
     * @param string[] $phpFiles
     * @return string[]
     */
    private function collectMissingClassPhpdoc(array $phpFiles): array
    {
        $hits = [];

        foreach ($phpFiles as $path) {
            $lines = $this->readLines($path);
            $lineCount = count($lines);

            for ($i = 0; $i < $lineCount; $i++) {
                $line = $lines[$i];

                if (!preg_match('/^\s*(abstract\s+|final\s+)?(class|enum|interface|trait)\s+\w+/', $line)) {
                    continue;
                }

                if (!$this->hasDocBlock($lines, $i)) {
                    $hits[] = sprintf('%s:%d  %s', $path, $i + 1, trim($line));
                }
            }
        }

        return $hits;
    }

    /**
     * @param string[] $phpFiles
     * @return string[]
     */
    private function collectMissingPublicMethodPhpdoc(array $phpFiles): array
    {
        $hits = [];

        foreach ($phpFiles as $path) {
            $lines = $this->readLines($path);
            $lineCount = count($lines);

            for ($i = 0; $i < $lineCount; $i++) {
                $line = $lines[$i];

                if (!preg_match('/^\s*public\s+(static\s+|abstract\s+|abstract\s+static\s+)?function\s+\w+/', $line)) {
                    continue;
                }

                if (!$this->hasDocBlock($lines, $i)) {
                    $hits[] = sprintf('%s:%d  %s', $path, $i + 1, trim($line));
                }
            }
        }

        return $hits;
    }

    /**
     * @param string[] $tsFiles
     * @return string[]
     */
    private function collectMissingJsdoc(array $tsFiles): array
    {
        $hits = [];

        foreach ($tsFiles as $path) {
            $lines = $this->readLines($path);
            $lineCount = count($lines);

            for ($i = 0; $i < $lineCount; $i++) {
                $line = $lines[$i];

                if (!preg_match('/^\s*export\s+(default\s+)?(function|class|const|type|interface)\s+\w+/', $line)) {
                    continue;
                }

                if (!$this->hasDocBlock($lines, $i)) {
                    $hits[] = sprintf('%s:%d  %s', $path, $i + 1, trim($line));
                }
            }
        }

        return $hits;
    }

    /**
     * @param string[] $files
     */
    private function printFileValidation(array $files): int
    {
        if ($files === []) {
            echo "(no files to validate)\n";
            return 0;
        }

        $fileArgs = implode(' ', array_map('escapeshellarg', $files));
        [$exitCode, $lines] = $this->runCommand('php scripts/validate-files.php --with-types --review-scope ' . $fileArgs);

        foreach ($lines as $line) {
            echo $line . "\n";
        }

        return $exitCode;
    }

    private function printTranslationValidation(): int
    {
        [$exitCode, $lines] = $this->runCommand('php scripts/validate-translations.php');

        foreach ($lines as $line) {
            echo $line . "\n";
        }

        return $exitCode;
    }

    /**
     * @param string[] $files
     */
    private function printBackendTestsValidation(array $files): int
    {
        if ($files === []) {
            echo "(no files to validate)\n";
            return 0;
        }

        $fileArgs = implode(' ', array_map('escapeshellarg', $files));
        [$exitCode, $lines] = $this->runCommand('php scripts/validate-backend-tests.php ' . $fileArgs);

        foreach ($lines as $line) {
            echo $line . "\n";
        }

        return $exitCode;
    }

    /**
     * @return array{0: int, 1: string[]}
     */
    private function runCommand(string $command): array
    {
        $lines = [];
        exec($command . ' 2>&1', $lines, $code);

        return [$code, $lines];
    }

    /**
     * @return string[]
     */
    private function readLines(string $path): array
    {
        $content = file_get_contents($path);

        if ($content === false) {
            return [];
        }

        return explode("\n", $content);
    }

    /**
     * @param string[] $lines
     */
    private function hasDocBlock(array $lines, int $lineIndex): bool
    {
        $inMultiLineAttr = false;

        for ($i = $lineIndex - 1; $i >= 0; $i--) {
            $previous = trim($lines[$i]);

            if ($previous === '') {
                return false;
            }

            if ($inMultiLineAttr) {
                if (str_starts_with($previous, '#[')) {
                    $inMultiLineAttr = false;
                }
                continue;
            }

            if ($previous === '*/' || (str_starts_with($previous, '/**') && str_ends_with($previous, '*/'))) {
                return true;
            }

            if (str_starts_with($previous, '//') || str_starts_with($previous, '*') || str_starts_with($previous, '@')) {
                continue;
            }

            if (str_starts_with($previous, '#[')) {
                if (!str_ends_with($previous, ']') && !str_ends_with($previous, ')]')) {
                    $inMultiLineAttr = true;
                }
                continue;
            }

            if (str_ends_with($previous, ')]') || str_ends_with($previous, ']')) {
                $inMultiLineAttr = true;
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * @param string[] $items
     */
    private function printList(array $items): void
    {
        if ($items === []) {
            echo "(none)\n";
            return;
        }

        foreach ($items as $item) {
            echo $item . "\n";
        }
    }

    /**
     * @param string[] $items
     */
    private function printPrefixedList(array $items, string $prefix): void
    {
        if ($items === []) {
            echo "(none)\n";
            return;
        }

        foreach ($items as $item) {
            echo $prefix . $item . "\n";
        }
    }
}
