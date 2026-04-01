#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run mechanical review checks on modified and untracked files
// Usage: php scripts/review.php
//
// Limitations:
//   French strings: detects accented Latin characters (U+00C0-U+00FF) only.
//   Does NOT catch unaccented French words (e.g. Valider, Commenter, Titre, Annuler).
//   Complement with a manual review of new visible strings in the git diff.
//
//   JSDoc check: covers export declarations (export function/class/const/type/interface Foo).
//   Does NOT check re-exports (export default someVar, export { Foo }, export type { Bar }).
//   Re-exports do not need adjacent JSDoc — the JSDoc belongs on the original declaration.

if (in_array('-h', $argv, true) || in_array('--help', $argv, true)) {
    echo "Run mechanical review checks on modified and untracked files\n\n";
    echo "Checks (blockers):\n";
    echo "  - French strings in .php (backend/src/) and .ts/.tsx (frontend/src/) files\n";
    echo "  - Missing PHPDoc on public PHP methods (backend/src/ only, not migrations)\n";
    echo "  - Missing JSDoc on exported TypeScript/React symbols\n\n";
    echo "Informational (no exit code impact):\n";
    echo "  - Modified files list\n";
    echo "  - Untracked files list\n\n";
    echo "Usage: php scripts/review.php\n";
    exit(0);
}

$root = dirname(__DIR__);
chdir($root);

// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Run a shell command, return [exit_code, lines[]].
 */
$run = static function (string $command): array {
    $lines = [];
    exec($command . ' 2>&1', $lines, $code);
    return [$code, $lines];
};

/**
 * Read file lines (0-indexed array).
 *
 * @return array<int, string>
 */
$readLines = static function (string $path): array {
    $content = file_get_contents($path);
    if ($content === false) {
        return [];
    }
    return explode("\n", $content);
};

/**
 * Walk backwards from line $i to find a closing JSDoc/PHPDoc marker.
 * Returns true if `*\/` is found before hitting a blank line or unrelated code.
 *
 * @param array<int, string> $lines
 */
$hasDocBlock = static function (array $lines, int $i): bool {
    for ($j = $i - 1; $j >= 0; $j--) {
        $prev = trim($lines[$j]);
        if ($prev === '') {
            return false;
        }
        if ($prev === '*/') {
            return true;
        }
        // Single-line comment, doc line, or decorator — keep looking
        if (str_starts_with($prev, '//') || str_starts_with($prev, '*') || str_starts_with($prev, '@')) {
            continue;
        }
        // Anything else terminates the search
        return false;
    }
    return false;
};

// ─── Collect git status ──────────────────────────────────────────────────────

[, $statusLines] = $run('git status --porcelain');

$modifiedFiles  = [];  // M/A in index or worktree
$untrackedFiles = [];  // ??

foreach ($statusLines as $line) {
    if (strlen($line) < 4) {
        continue;
    }
    $xy   = substr($line, 0, 2);
    $path = trim(substr($line, 3));

    // Handle renames: "old -> new" format
    if (str_contains($path, ' -> ')) {
        $path = substr($path, strrpos($path, ' -> ') + 4);
    }
    // Strip surrounding quotes that git adds for paths with spaces or special chars
    $path = trim($path, '"');

    if ($xy === '??') {
        $untrackedFiles[] = $path;
    } elseif (str_contains($xy, 'M') || str_contains($xy, 'A')) {
        $modifiedFiles[] = $path;
    }
}

// All files in scope for content checks (modified + untracked, excluding directories)
$allFiles = array_filter(
    array_merge($modifiedFiles, $untrackedFiles),
    static fn(string $p) => !str_ends_with($p, '/') && file_exists($p)
);

// ─── Section 1: Modified files (informational) ───────────────────────────────

echo "=== Modified files ===\n";
if ($modifiedFiles === []) {
    echo "(none)\n";
} else {
    foreach ($modifiedFiles as $f) {
        echo "M  $f\n";
    }
}
echo "\n";

// ─── Section 2: Untracked files (informational) ──────────────────────────────

echo "=== Untracked files ===\n";
if ($untrackedFiles === []) {
    echo "(none)\n";
} else {
    foreach ($untrackedFiles as $path) {
        echo "?? $path\n";
    }
}
echo "\n";

// ─── Section 3: French strings ───────────────────────────────────────────────

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

// Regex: matches accented Latin characters (U+00C0 to U+00FF, plus U+0152/U+0153 for OE ligature)
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

// ─── Section 4: Missing PHPDoc on public PHP methods ─────────────────────────

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

        // Detect public function declarations (including abstract, static combinations)
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

// ─── Section 5: Missing JSDoc on exported TS/React symbols ───────────────────

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

        // Detect export declarations: export function, export const, export default function/class
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

// ─── Exit code ───────────────────────────────────────────────────────────────

$hasBlockers = $frenchHits !== [] || $missingPhpdoc !== [] || $missingJsdoc !== [];
exit($hasBlockers ? 1 : 0);
