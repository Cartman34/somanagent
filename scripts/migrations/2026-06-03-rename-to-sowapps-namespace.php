#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 *
 * Purpose:      Rename PHP namespaces App\* → Sowapps\SoManAgent\* (backend + tests)
 *               and SoManAgent\Script\* → Sowapps\SoManAgent\Script\* (scripts/src).
 *               Updates composer.json packages, Symfony config files, PHPStan baseline,
 *               and string patterns referencing old namespaces.
 * Introduced:   2026-06-03
 * Remove after: All known WAs and WPs have been migrated and no agent session still
 *               runs against the old namespace structure.
 *               Tracked in doc/development/migrations.md.
 *
 * Behaviour:
 * - Pre-audit: detects DQL short-form App:Entity and unexpected occurrences.
 * - Edits backend/composer.json and scripts/composer.json.
 * - Applies targeted substitutions in Symfony config YAML and entry-point PHP files.
 * - Updates config/phpstan-baseline.neon and ValidateBackendTestsRunner.php patterns.
 * - Builds a FQCN → FQCN map from PHP sources and runs Rector RenameClassRector.
 * - Runs a post-Rector pass to replace remaining App\ in DQL string literals.
 * - Idempotent: each step checks whether the target state is already in place.
 */

declare(strict_types=1);

$projectRoot   = dirname(__DIR__, 2);
$markerPath    = $projectRoot . '/local/backlog/migrations.applied';
$migrationName = basename(__FILE__);

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * @param string $path    Project-relative path
 * @param string $old     Exact string to search (in raw file bytes)
 * @param string $new     Replacement string
 * @param string $label   Human-readable description for the log
 * @param string $projectRoot
 * @return bool           true when the file was modified
 */
function replaceInFile(string $path, string $old, string $new, string $label, string $projectRoot): bool
{
    $abs = $projectRoot . '/' . $path;
    $content = file_get_contents($abs);
    if ($content === false) {
        fwrite(STDERR, "ERROR: cannot read {$path}\n");
        exit(1);
    }
    if (!str_contains($content, $old)) {
        echo "  skip (already done): {$label}\n";
        return false;
    }
    $updated = str_replace($old, $new, $content);
    writeTmpAndRename($abs, $updated, $path);
    echo "  updated: {$label}\n";
    return true;
}

/**
 * @param string $abs     Absolute target path
 * @param string $content File content to write
 * @param string $label   Human-readable path for error messages
 */
function writeTmpAndRename(string $abs, string $content, string $label): void
{
    $tmp = $abs . '.migration.tmp';
    if (file_put_contents($tmp, $content) === false) {
        fwrite(STDERR, "ERROR: cannot write temp file for {$label}\n");
        exit(1);
    }
    if (!rename($tmp, $abs)) {
        fwrite(STDERR, "ERROR: cannot rename temp file for {$label}\n");
        exit(1);
    }
}

/**
 * Edit a JSON file by decoding, applying a callback, then re-encoding.
 *
 * @param string   $path
 * @param callable $mutate   Receives the decoded array (by reference), returns true if changed
 * @param string   $projectRoot
 * @return bool
 */
function editJson(string $path, callable $mutate, string $projectRoot): bool
{
    $abs     = $projectRoot . '/' . $path;
    $raw     = file_get_contents($abs);
    if ($raw === false) {
        fwrite(STDERR, "ERROR: cannot read {$path}\n");
        exit(1);
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!$mutate($data)) {
        return false;
    }
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    writeTmpAndRename($abs, $encoded, $path);
    return true;
}

/**
 * Collect a FQCN → new FQCN map from PHP files under $dir.
 * Only files whose namespace starts with $oldNsPrefix are included.
 *
 * @param string $dir
 * @param string $oldNsPrefix  e.g. "App" or "SoManAgent\Script"
 * @param string $newNsPrefix  e.g. "Sowapps\SoManAgent" or "Sowapps\SoManAgent\Script"
 * @param string $projectRoot
 * @return array<string,string>
 */
function collectFqcnMap(string $dir, string $oldNsPrefix, string $newNsPrefix, string $projectRoot): array
{
    $abs  = $projectRoot . '/' . $dir;
    if (!is_dir($abs)) {
        return [];
    }

    $map  = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
    );

    $nsPattern    = '/^namespace\s+(' . preg_quote($oldNsPrefix, '/') . '(?:\\\\[\w\\\\]*)?);\s*$/m';
    $classPattern = '/^(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m';

    foreach ($iter as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }

        if (!preg_match($nsPattern, $content, $nsMatch)) {
            continue;
        }

        $namespace = $nsMatch[1];
        if (!preg_match_all($classPattern, $content, $classMatches)) {
            continue;
        }

        foreach ($classMatches[1] as $className) {
            $oldFqcn = $namespace . '\\' . $className;
            $newFqcn = $newNsPrefix . '\\' . substr($namespace, strlen($oldNsPrefix) + 1);
            $newFqcn = rtrim($newFqcn, '\\') . '\\' . $className;
            $map[$oldFqcn] = $newFqcn;
        }
    }

    return $map;
}

/**
 * Write a temporary Rector config for RenameClassRector and return its absolute path.
 *
 * @param array<string,string> $map
 * @param string               $projectRoot
 * @return string
 */
function writeRectorConfig(array $map, string $projectRoot): string
{
    $configPath = $projectRoot . '/config/rector-rename-namespace-migration.php';

    $entries = [];
    foreach ($map as $old => $new) {
        $entries[] = sprintf("    '%s' => '%s',", addslashes($old), addslashes($new));
    }
    $entriesStr = implode("\n", $entries);

    $backendSrc        = addslashes($projectRoot . '/backend/src');
    $backendTests      = addslashes($projectRoot . '/backend/tests');
    $scriptsSrc        = addslashes($projectRoot . '/scripts/src');
    $backendAutoload   = addslashes($projectRoot . '/backend/vendor/autoload.php');
    $scriptsAutoload   = addslashes($projectRoot . '/scripts/vendor/autoload.php');

    $content = <<<PHP
<?php
/**
 * Temporary Rector config for migration 2026-06-03-rename-to-sowapps-namespace.
 * Generated by the migration script — do not edit manually.
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

return RectorConfig::configure()
    ->withPaths([
        '{$backendSrc}',
        '{$backendTests}',
        '{$scriptsSrc}',
    ])
    ->withBootstrapFiles([
        '{$backendAutoload}',
        '{$scriptsAutoload}',
    ])
    ->withImportNames(importNames: true, importDocBlockNames: true, importShortClasses: false, removeUnusedImports: true)
    ->withConfiguredRule(RenameClassRector::class, [
{$entriesStr}
    ]);
PHP;

    file_put_contents($configPath, $content);
    return $configPath;
}

/**
 * Perform a post-Rector pass on PHP files under $dirs to replace remaining $old with $new.
 * This covers DQL string literals that Rector does not process.
 *
 * @param array<string> $dirs  Project-relative directories
 * @param string        $old   In raw file bytes
 * @param string        $new
 * @param string        $projectRoot
 * @return int  Number of files modified
 */
function postRectorStringPass(array $dirs, string $old, string $new, string $projectRoot): int
{
    $modified = 0;
    foreach ($dirs as $dir) {
        $abs = $projectRoot . '/' . $dir;
        if (!is_dir($abs)) {
            continue;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if ($content === false || !str_contains($content, $old)) {
                continue;
            }
            $updated = str_replace($old, $new, $content);
            writeTmpAndRename($file->getPathname(), $updated, $file->getPathname());
            $modified++;
        }
    }
    return $modified;
}

// ─── Entry point ──────────────────────────────────────────────────────────────

echo "Migration {$migrationName}\n";
echo str_repeat('─', 70) . "\n\n";

// ─── Step 1: Pre-audit ────────────────────────────────────────────────────────

echo "[1/9] Pre-audit\n";

$auditDirs    = ['backend/src', 'backend/tests', 'scripts/src'];
$dqlShortForm = 0;

foreach ($auditDirs as $auditDir) {
    $abs = $projectRoot . '/' . $auditDir;
    if (!is_dir($abs)) {
        continue;
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }
        // DQL short form: App:Entity (colon notation, not backslash)
        if (preg_match('/\bApp:[A-Z]\w+/', $content)) {
            $rel = substr($file->getPathname(), strlen($projectRoot) + 1);
            echo "  WARNING DQL short form App:Entity found in {$rel}\n";
            $dqlShortForm++;
        }
    }
}

if ($dqlShortForm > 0) {
    fwrite(STDERR, "ERROR: {$dqlShortForm} file(s) contain DQL short-form App:Entity — resolve them before running this migration.\n");
    exit(1);
}
echo "  OK — no DQL short-form App:Entity found\n\n";

// ─── Step 2: backend/composer.json ────────────────────────────────────────────

echo "[2/9] backend/composer.json\n";
$changed = editJson('backend/composer.json', static function (array &$data): bool {
    $mutated = false;
    if (($data['name'] ?? '') !== 'sowapps/somanagent') {
        $data = ['name' => 'sowapps/somanagent'] + $data;
        $mutated = true;
    }
    if (isset($data['autoload']['psr-4']['App\\'])) {
        $data['autoload']['psr-4']['Sowapps\\SoManAgent\\'] = $data['autoload']['psr-4']['App\\'];
        unset($data['autoload']['psr-4']['App\\']);
        $mutated = true;
    }
    if (isset($data['autoload-dev']['psr-4']['App\\Tests\\'])) {
        $data['autoload-dev']['psr-4']['Sowapps\\SoManAgent\\Tests\\'] = $data['autoload-dev']['psr-4']['App\\Tests\\'];
        unset($data['autoload-dev']['psr-4']['App\\Tests\\']);
        $mutated = true;
    }
    return $mutated;
}, $projectRoot);
echo $changed ? "  updated\n\n" : "  skip (already done)\n\n";

// ─── Step 3: scripts/composer.json ────────────────────────────────────────────

echo "[3/9] scripts/composer.json\n";
$changed = editJson('scripts/composer.json', static function (array &$data): bool {
    $mutated = false;
    if (($data['name'] ?? '') !== 'sowapps/somanagent-scripts') {
        $data['name'] = 'sowapps/somanagent-scripts';
        $mutated = true;
    }
    if (isset($data['autoload']['psr-4']['SoManAgent\\Script\\'])) {
        $data['autoload']['psr-4']['Sowapps\\SoManAgent\\Script\\'] = $data['autoload']['psr-4']['SoManAgent\\Script\\'];
        unset($data['autoload']['psr-4']['SoManAgent\\Script\\']);
        $mutated = true;
    }
    return $mutated;
}, $projectRoot);
echo $changed ? "  updated\n\n" : "  skip (already done)\n\n";

// ─── Step 4: Symfony config YAML files ────────────────────────────────────────

echo "[4/9] Symfony config YAML\n";

// services.yaml: all App\ class references
replaceInFile('backend/config/services.yaml', 'App\\', 'Sowapps\\SoManAgent\\', 'services.yaml App\\', $projectRoot);

// routes.yaml
replaceInFile('backend/config/routes.yaml', 'App\\Controller', 'Sowapps\\SoManAgent\\Controller', 'routes.yaml namespace', $projectRoot);

// doctrine.yaml: mapping name, prefix, alias
replaceInFile('backend/config/packages/doctrine.yaml', "\n            App:\n", "\n            SoManAgent:\n", 'doctrine.yaml mapping name', $projectRoot);
replaceInFile('backend/config/packages/doctrine.yaml', "prefix: 'App\\Entity'", "prefix: 'Sowapps\\SoManAgent\\Entity'", 'doctrine.yaml prefix', $projectRoot);
replaceInFile('backend/config/packages/doctrine.yaml', 'alias: App', 'alias: SoManAgent', 'doctrine.yaml alias', $projectRoot);

// messenger.yaml
replaceInFile('backend/config/packages/messenger.yaml', 'App\\Message\\', 'Sowapps\\SoManAgent\\Message\\', 'messenger.yaml routing', $projectRoot);

echo "\n";

// ─── Step 5: PHP entry points ─────────────────────────────────────────────────

echo "[5/9] PHP entry points\n";
replaceInFile('backend/bin/console', 'use App\\Kernel;', 'use Sowapps\\SoManAgent\\Kernel;', 'bin/console use App\Kernel', $projectRoot);
replaceInFile('backend/public/index.php', 'use App\\Kernel;', 'use Sowapps\\SoManAgent\\Kernel;', 'public/index.php use App\Kernel', $projectRoot);
echo "\n";

// ─── Step 6: PHPStan baseline ─────────────────────────────────────────────────

echo "[6/9] config/phpstan-baseline.neon\n";
// In the raw NEON file, App\\ appears as two characters: App followed by \\
// We replace App\\ → Sowapps\\SoManAgent\\ in the raw bytes
$baselineAbs = $projectRoot . '/config/phpstan-baseline.neon';
$baseline    = file_get_contents($baselineAbs);
if ($baseline === false) {
    fwrite(STDERR, "ERROR: cannot read config/phpstan-baseline.neon\n");
    exit(1);
}
if (str_contains($baseline, 'App\\\\')) {
    $baseline = str_replace('App\\\\', 'Sowapps\\\\SoManAgent\\\\', $baseline);
    writeTmpAndRename($baselineAbs, $baseline, 'config/phpstan-baseline.neon');
    echo "  updated\n\n";
} else {
    echo "  skip (already done)\n\n";
}

// ─── Step 7: ValidateBackendTestsRunner.php string patterns ───────────────────

echo "[7/9] ValidateBackendTestsRunner.php explicit string patterns\n";
$runnerPath = 'scripts/src/Runner/ValidateBackendTestsRunner.php';

// Key: error message text that references App\Tests\Support\LocalUnitTestCase
replaceInFile(
    $runnerPath,
    'Local unit tests must extend App\\\\Tests\\\\Support\\\\LocalUnitTestCase.',
    'Local unit tests must extend Sowapps\\\\SoManAgent\\\\Tests\\\\Support\\\\LocalUnitTestCase.',
    'FORBIDDEN_PATTERNS key (LocalUnitTestCase message)',
    $projectRoot
);

// Value: regex pattern for external AI/VCS adapters
replaceInFile(
    $runnerPath,
    'App\\\\\\\\Adapter\\\\\\\\AI\\\\\\\\|App\\\\\\\\Adapter\\\\\\\\VCS\\\\\\\\',
    'Sowapps\\\\\\\\SoManAgent\\\\\\\\Adapter\\\\\\\\AI\\\\\\\\|Sowapps\\\\\\\\SoManAgent\\\\\\\\Adapter\\\\\\\\VCS\\\\\\\\',
    'FORBIDDEN_PATTERNS value (AI/VCS adapter regex)',
    $projectRoot
);

echo "\n";

// ─── Step 8: PHP namespace rename via Rector RenameClassRector ────────────────

echo "[8/9] PHP namespace rename via Rector\n";

$backendMap = collectFqcnMap('backend/src', 'App', 'Sowapps\\SoManAgent', $projectRoot);
$testsMap   = collectFqcnMap('backend/tests', 'App', 'Sowapps\\SoManAgent', $projectRoot);
$scriptsMap = collectFqcnMap('scripts/src', 'SoManAgent\\Script', 'Sowapps\\SoManAgent\\Script', $projectRoot);

$fullMap = array_merge($backendMap, $testsMap, $scriptsMap);

if ($fullMap === []) {
    echo "  skip (no old-namespace classes found — already migrated)\n\n";
} else {
    echo '  ' . count($fullMap) . " FQCN entries collected for RenameClassRector\n";

    $rectorConfig = writeRectorConfig($fullMap, $projectRoot);
    echo "  wrote temp Rector config: config/rector-rename-namespace-migration.php\n";

    $rectorBin = $projectRoot . '/scripts/vendor/bin/rector';
    $cmd       = 'php ' . escapeshellarg($rectorBin)
        . ' process --config ' . escapeshellarg($rectorConfig)
        . ' --no-progress-bar';

    echo "  running Rector...\n";
    passthru($cmd, $rectorExit);

    unlink($rectorConfig);
    echo "  removed temp Rector config\n";

    // Rector exits 0 (no changes) or 1 (changes applied) — both are success.
    // Exit codes >= 2 indicate a real error.
    if ($rectorExit >= 2) {
        fwrite(STDERR, "ERROR: Rector exited with code {$rectorExit}\n");
        exit(1);
    }
    echo "\n";
}

// ─── Step 9: Post-Rector passes ───────────────────────────────────────────────

echo "[9/9] Post-Rector passes\n";

// 9a — remaining App\ in DQL string literals (backend only)
$modified = postRectorStringPass(
    ['backend/src', 'backend/tests'],
    'App\\',
    'Sowapps\\SoManAgent\\',
    $projectRoot
);
if ($modified > 0) {
    echo "  9a: replaced remaining App\\ in {$modified} PHP file(s)\n";
} else {
    echo "  9a: skip (no remaining App\\ found)\n";
}

// 9b — scripts namespace declarations (Rector only updates references, not definitions)
$modified = postRectorStringPass(
    ['scripts/src'],
    'namespace SoManAgent\\Script',
    'namespace Sowapps\\SoManAgent\\Script',
    $projectRoot
);
if ($modified > 0) {
    echo "  9b: updated namespace declarations in {$modified} scripts PHP file(s)\n";
} else {
    echo "  9b: skip (scripts namespace declarations already correct)\n";
}

// 9c — root App namespace declaration (Kernel.php: `namespace App;` has no backslash, missed by 9a)
$modified = postRectorStringPass(
    ['backend/src', 'backend/tests'],
    'namespace App;',
    'namespace Sowapps\\SoManAgent;',
    $projectRoot
);
if ($modified > 0) {
    echo "  9c: updated root App namespace declaration in {$modified} PHP file(s)\n\n";
} else {
    echo "  9c: skip (root App namespace already correct)\n\n";
}

// ─── Mark applied ─────────────────────────────────────────────────────────────

$applied = is_file($markerPath)
    ? (file($markerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
    : [];

if (in_array($migrationName, $applied, true)) {
    echo "Marker already present — no update needed.\n";
} else {
    $applied[] = $migrationName;
    sort($applied);
    if (file_put_contents($markerPath, implode("\n", $applied) . "\n") === false) {
        fwrite(STDERR, "ERROR: cannot update {$markerPath}\n");
        exit(1);
    }
    echo "Marked applied: {$migrationName}\n";
}

echo "\nDone.\n";
