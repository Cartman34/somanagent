#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Convert inline single-line PHPDoc comments mixing description + @tag to proper multi-line blocks
// Usage: php scripts/fix-inline-phpdoc.php [--dry-run]
// PHPStan does not parse @return/@param tags from /** Description. @tag type */ single-line comments.

$dryRun = in_array('--dry-run', $argv, true);
$srcDir = __DIR__ . '/../backend/src';

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
$changed = [];

foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $original = file_get_contents($path);

    // Match single-line /** ... @tag ... */ that have BOTH description text AND at least one @tag.
    // The description must be non-empty (has chars before the first @).
    // Supports multiple @tags on the same line by splitting on @ boundaries.
    $fixed = preg_replace_callback(
        '/^(\s*)\/\*\* ([^@*\n][^@\n]*?)[ \t]+(@(?:return|param|var|throws)[^*\n]+?) \*\/$/m',
        static function (array $m): string {
            $indent = $m[1];
            $desc   = rtrim($m[2]);
            $tags   = trim($m[3]);

            // Split multiple inline tags (rare but possible)
            $tagLines = preg_split('/\s+(?=@(?:return|param|var|throws))/', $tags);

            $lines = ["{$indent}/**", "{$indent} * {$desc}"];
            foreach ($tagLines as $tag) {
                $lines[] = "{$indent} * " . trim($tag);
            }
            $lines[] = "{$indent} */";

            return implode("\n", $lines);
        },
        $original,
    );

    if ($fixed !== $original) {
        $changed[] = str_replace($srcDir . '/', '', $path);
        if (!$dryRun) {
            file_put_contents($path, $fixed);
        }
    }
}

if (empty($changed)) {
    echo "No files need fixing.\n";
} else {
    $verb = $dryRun ? 'Would fix' : 'Fixed';
    echo "{$verb} " . count($changed) . " file(s):\n";
    foreach ($changed as $rel) {
        echo "  {$rel}\n";
    }
}
