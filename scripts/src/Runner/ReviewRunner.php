<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Backlog\Service\BacklogScopeService;

/**
 * Review script runner.
 *
 * Runs mechanical review checks on modified and untracked files.
 */
final class ReviewRunner extends AbstractScriptRunner
{
    private const NAME = 'review';

    protected function getName(): string
    {
        return self::NAME;
    }

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
            ['name' => '--scope-dir', 'description' => 'Restrict the branch diff to this directory prefix (repeatable; omit for no restriction)'],
        ];
    }

    /**
     * Executes the review checks against modified and untracked files.
     */
    public function run(array $args): int
    {
        $base = $this->parseBaseOption($args);
        $scopeDirs = $this->parseScopeDirOption($args);
        $baseCommit = $base !== null ? $this->resolveBaseCommit($base) : null;
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
            echo "=== Review base ===\n";
            echo "{$base} => {$baseCommit}\n\n";

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

        echo "=== PHPStan static analysis ===\n";
        $phpstanExitCode = $this->printPhpstanValidation($phpFiles);
        echo "\n";

        $reusedLiterals = $this->collectReusedStringLiterals($phpFiles);
        echo "=== Reused domain string literals (missing enum/constant) ===\n";
        $this->printList($reusedLiterals);
        echo "\n";

        $scopeViolations = [];
        if ($scopeDirs !== null) {
            $branchFiles = $this->collectAllBranchFiles($base);
            $scopeViolations = (new BacklogScopeService())->collectScopeViolations($branchFiles, $scopeDirs);
            echo "=== Branch scope check (allowed dirs: " . implode(', ', $scopeDirs) . ") ===\n";
            $this->printList($scopeViolations);
            echo "\n";
        }

        $hasBlockers = $frenchHits !== [] || $missingPhpdoc !== [] || $missingJsdoc !== []
            || $validateExitCode !== 0
            || $translationExitCode !== 0
            || $backendTestsExitCode !== 0
            || $phpstanExitCode !== 0
            || $reusedLiterals !== []
            || $scopeViolations !== [];

        return $hasBlockers ? 1 : 0;
    }

    /**
     * @param array<string> $args
     * @deprecated Use parseArgs() instead.
     */
    private function parseBaseOption(array $args): ?string
    {
        $base = null;

        while ($args !== []) {
            $arg = $args[0];
            array_shift($args);

            if (str_starts_with($arg, '--base=')) {
                $base = substr($arg, strlen('--base='));
                continue;
            }

            if ($arg === '--base') {
                $base = (string) array_shift($args);
                continue;
            }

            if (str_starts_with($arg, '--scope-dir=') || $arg === '--scope-dir') {
                continue; // consumed by parseScopeDirOption
            }

            throw new \RuntimeException("Unknown review argument: {$arg}");
        }

        $base = trim((string) $base);

        return $base !== '' ? $base : null;
    }

    /**
     * Parses all `--scope-dir=<dir>` occurrences from the argument list.
     *
     * Returns null when no `--scope-dir` argument is present (ALL — no restriction),
     * or a non-empty list of normalized directory prefixes (each ending with `/`).
     *
     * @param array<string> $args
     * @return list<string>|null
     */
    private function parseScopeDirOption(array $args): ?array
    {
        $dirs = [];

        while ($args !== []) {
            $arg = array_shift($args);

            if (str_starts_with($arg, '--scope-dir=')) {
                $dirs[] = rtrim(substr($arg, strlen('--scope-dir=')), '/') . '/';
                continue;
            }

            if ($arg === '--scope-dir') {
                $next = array_shift($args);
                if ($next !== null) {
                    $dirs[] = rtrim($next, '/') . '/';
                }
            }
        }

        return $dirs !== [] ? $dirs : null;
    }

    /**
     * Collects all files touched by the branch, including both sides of renames and deleted files.
     *
     * For the scope check we must include deleted files (which are absent from `filterExistingFiles`)
     * and both the old and new path of any rename/copy.
     *
     * @return list<string>
     */
    private function collectAllBranchFiles(?string $base): array
    {
        $files = [];

        // Working-tree changes: both sides of renames
        [, $statusLines] = $this->runCommand('git status --porcelain');
        foreach ($statusLines as $line) {
            if (strlen($line) < 4) {
                continue;
            }
            $path = trim(substr($line, 3));
            $path = trim($path, '"');
            if (str_contains($path, ' -> ')) {
                $source = trim(substr($path, 0, strpos($path, ' -> ')), '"');
                $dest = trim(substr($path, strrpos($path, ' -> ') + 4), '"');
                $files[] = $source;
                $files[] = $dest;
            } else {
                $files[] = $path;
            }
        }

        // Committed changes since base: all filters including delete and mode-change, both sides of renames
        if ($base !== null && $base !== '') {
            [$code, $lines] = $this->runCommand(sprintf(
                'git diff --name-status --diff-filter=ACDMRT %s..HEAD',
                escapeshellarg($base),
            ));
            if ($code === 0) {
                foreach ($lines as $line) {
                    if (trim($line) === '') {
                        continue;
                    }
                    $parts = explode("\t", $line);
                    $status = $parts[0] ?? '';
                    if (str_starts_with($status, 'R') || str_starts_with($status, 'C')) {
                        if (isset($parts[1]) && $parts[1] !== '') {
                            $files[] = $parts[1];
                        }
                        if (isset($parts[2]) && $parts[2] !== '') {
                            $files[] = $parts[2];
                        }
                    } elseif (isset($parts[1]) && $parts[1] !== '') {
                        $files[] = $parts[1];
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($files, static fn(string $f): bool => $f !== '')));
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

    private function resolveBaseCommit(string $base): string
    {
        [$resolveExitCode, $resolveLines] = $this->runCommand(sprintf(
            'git rev-parse --verify %s',
            escapeshellarg($base . '^{commit}'),
        ));
        if ($resolveExitCode !== 0) {
            throw new \RuntimeException(sprintf('Review base ref does not resolve to a commit: %s.', $base));
        }

        [$ancestorExitCode] = $this->runCommand(sprintf(
            'git merge-base --is-ancestor %s HEAD',
            escapeshellarg($base),
        ));
        if ($ancestorExitCode !== 0) {
            throw new \RuntimeException(sprintf('Review base ref is not an ancestor of HEAD: %s.', $base));
        }

        return trim($resolveLines[0] ?? '');
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
            if ($this->isPhpdocExemptPath($path)) {
                continue;
            }

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
            if ($this->isPhpdocExemptPath($path)) {
                continue;
            }

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

    private function isPhpdocExemptPath(string $path): bool
    {
        return str_starts_with($path, 'backend/tests/')
            || str_starts_with($path, 'scripts/src/Test/');
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
     * @param string[] $phpFiles
     */
    private function printPhpstanValidation(array $phpFiles): int
    {
        $scopes = $this->resolvePhpstanScopes($phpFiles);
        if ($scopes === []) {
            echo "(no backend or scripts PHP source files modified)\n";
            return 0;
        }

        if (!is_file($this->projectRoot . '/scripts/vendor/bin/phpstan')) {
            echo "PHPStan: UNAVAILABLE (run php scripts/scripts-install.php)\n";
            return 0;
        }

        $scopeArgs = implode(' ', array_map(
            static fn(string $scope): string => '--scope=' . escapeshellarg($scope),
            $scopes
        ));
        [$exitCode, $lines] = $this->runCommand('php scripts/phpstan.php ' . $scopeArgs);

        foreach ($lines as $line) {
            echo $line . "\n";
        }

        return $exitCode;
    }

    /**
     * @param string[] $phpFiles
     * @return list<string>
     */
    private function resolvePhpstanScopes(array $phpFiles): array
    {
        $scopes = [];
        foreach ($phpFiles as $path) {
            if (str_starts_with($path, 'backend/src/')) {
                $scopes[] = 'backend';
                continue;
            }

            if (str_starts_with($path, 'scripts/src/')) {
                $scopes[] = 'scripts';
            }
        }

        return array_values(array_unique($scopes));
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
     * Detects kebab-case PHP string literals repeated 2+ times in the same file.
     *
     * A kebab-case literal appearing multiple times in a single file is a signal that a
     * BacklogCommandName or BacklogCliOption constant is missing. False positives are
     * acceptable: the output points the developer toward the right enum.
     *
     * Skips enum/constant definition files so the source-of-truth itself is never flagged.
     *
     * @param string[] $phpFiles
     * @return string[]
     */
    private function collectReusedStringLiterals(array $phpFiles): array
    {
        $hits = [];

        foreach ($phpFiles as $path) {
            if ($this->isStringLiteralExemptPath($path)) {
                continue;
            }

            $lines = $this->readLines($path);
            $counts = [];

            foreach ($lines as $lineNum => $lineContent) {
                preg_match_all('/(?<![A-Za-z_>])["\']([a-z][a-z0-9]*(?:-[a-z0-9]+)+)["\']/', $lineContent, $matches);
                foreach ($matches[1] as $literal) {
                    $counts[$literal][] = $lineNum + 1;
                }
            }

            foreach ($counts as $literal => $lineNums) {
                if (count($lineNums) >= 2) {
                    $hits[] = sprintf(
                        '%s  literal \'%s\' repeated %dx (lines %s) — use BacklogCommandName or BacklogCliOption',
                        $path,
                        $literal,
                        count($lineNums),
                        implode(', ', array_unique($lineNums)),
                    );
                }
            }
        }

        return $hits;
    }

    private function isStringLiteralExemptPath(string $path): bool
    {
        return str_ends_with($path, 'Enum/BacklogCommandName.php')
            || str_ends_with($path, 'Enum/BacklogCliOption.php');
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
