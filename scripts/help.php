#!/usr/bin/env php
<?php
// Description: List all available scripts with their description and usage examples
// Usage: php scripts/help.php
// Usage: php scripts/help.php <script-name>

require_once __DIR__ . '/src/Application.php';

try {
    $app = new Application();
    $app->boot();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "\n❌ " . $e->getMessage() . "\n\n");
    exit(1);
}

$c          = $app->console;
$root       = dirname(__DIR__);
$scriptsDir = "$root/scripts";

// ── Local helpers (specific to this script) ───────────────────────────────────

/**
 * Parse the header annotations of a script file.
 * Supported formats:
 *   PHP  : // Description: ...  /  // Usage: ...
 *   Bash : # Description: ...   /  # Usage: ...
 *
 * @return array{description: string, usages: string[]}
 */
function parseHeader(string $file): array
{
    $lines       = file($file, FILE_IGNORE_NEW_LINES);
    $description = '';
    $usages      = [];

    foreach ($lines as $i => $line) {
        if ($i === 0 && str_starts_with($line, '#!')) continue; // shebang
        if (trim($line) === '<?php') continue;

        $content = null;
        if (str_starts_with($line, '// ')) {
            $content = substr($line, 3);
        } elseif (str_starts_with($line, '# ')) {
            $content = substr($line, 2);
        } elseif ($line === '#' || $line === '//') {
            continue;
        } else {
            break; // end of header block
        }

        if (str_starts_with($content, 'Description: ')) {
            $description = substr($content, 13);
        } elseif (str_starts_with($content, 'Usage: ')) {
            $usages[] = substr($content, 7);
        }
    }

    return ['description' => $description, 'usages' => $usages];
}

function ansi(string $text, string $color): string
{
    static $codes = [
        'bold'   => "\033[1m",
        'green'  => "\033[32m",
        'yellow' => "\033[33m",
        'cyan'   => "\033[36m",
        'reset'  => "\033[0m",
    ];
    return ($codes[$color] ?? '') . $text . $codes['reset'];
}

// ── Collect scripts — only direct files, skip sub-directories (src/) ──────────

$scripts = [];
foreach (scandir($scriptsDir) as $file) {
    if ($file === '.' || $file === '..') continue;
    $fullPath = "$scriptsDir/$file";
    if (!is_file($fullPath)) continue;
    if (!in_array(pathinfo($file, PATHINFO_EXTENSION), ['php', 'sh'], true)) continue;
    $scripts[$file] = parseHeader($fullPath);
}
ksort($scripts);

// ── Detail view for a specific script ─────────────────────────────────────────

if ($argc > 1) {
    $search = $argv[1];
    $found  = false;
    foreach ($scripts as $name => $meta) {
        if ($name === $search || $name === "$search.php" || $name === "$search.sh") {
            echo ansi($name, 'bold') . "\n";
            echo '  ' . ansi($meta['description'], 'cyan') . "\n\n";
            foreach ($meta['usages'] as $usage) {
                echo '  ' . ansi('$', 'green') . " $usage\n";
            }
            $found = true;
        }
    }
    if (!$found) {
        $c->fail("Script \"$search\" not found.");
    }
    exit(0);
}

// ── Full listing ──────────────────────────────────────────────────────────────

echo "\n" . ansi('SoManAgent — Available scripts', 'bold') . "\n";
echo str_repeat('─', 60) . "\n\n";

foreach ($scripts as $name => $meta) {
    $ext   = pathinfo($name, PATHINFO_EXTENSION);
    $badge = $ext === 'sh' ? ansi('[bash]', 'yellow') : ansi('[php] ', 'green');
    echo "  $badge " . ansi($name, 'bold') . "\n";
    if ($meta['description']) {
        echo "         {$meta['description']}\n";
    }
    if ($meta['usages']) {
        echo '         ' . ansi('→ ', 'cyan') . $meta['usages'][0] . "\n";
    }
    echo "\n";
}

echo '  ' . ansi('Details: ', 'cyan') . "php scripts/help.php <script-name>\n\n";
