#!/usr/bin/env php
<?php
// Description: Affiche la liste de tous les scripts disponibles avec leur description et exemples d'usage
// Usage: php scripts/help.php
// Usage: php scripts/help.php <nom-du-script>

$root       = dirname(__DIR__);
$scriptsDir = "$root/scripts";

/**
 * Parse les annotations d'entête d'un fichier script.
 * Formats supportés :
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

        // Commentaire PHP ou Bash
        $content = null;
        if (str_starts_with($line, '// ')) {
            $content = substr($line, 3);
        } elseif (str_starts_with($line, '# ')) {
            $content = substr($line, 2);
        } elseif ($line === '#' || $line === '//') {
            continue;
        } else {
            break; // fin du bloc d'entête
        }

        if (str_starts_with($content, 'Description: ')) {
            $description = substr($content, 13);
        } elseif (str_starts_with($content, 'Usage: ')) {
            $usages[] = substr($content, 7);
        }
    }

    return ['description' => $description, 'usages' => $usages];
}

function colorize(string $text, string $color): string
{
    $colors = ['green' => "\033[32m", 'yellow' => "\033[33m", 'cyan' => "\033[36m", 'reset' => "\033[0m", 'bold' => "\033[1m"];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

// Collecter tous les scripts
$scripts = [];
foreach (scandir($scriptsDir) as $file) {
    if ($file === '.' || $file === '..') continue;
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    if (!in_array($ext, ['php', 'sh'], true)) continue;
    $scripts[$file] = parseHeader("$scriptsDir/$file");
}
ksort($scripts);

// Filtre sur un script précis
if ($argc > 1) {
    $search = $argv[1];
    $found  = false;
    foreach ($scripts as $name => $meta) {
        if ($name === $search || $name === "$search.php" || $name === "$search.sh") {
            echo colorize($name, 'bold') . "\n";
            echo "  " . colorize($meta['description'], 'cyan') . "\n\n";
            foreach ($meta['usages'] as $usage) {
                echo "  " . colorize('$', 'green') . " $usage\n";
            }
            $found = true;
        }
    }
    if (!$found) {
        echo "Script \"$search\" introuvable.\n";
        exit(1);
    }
    exit(0);
}

// Affichage global
echo "\n" . colorize('SoManAgent — Scripts disponibles', 'bold') . "\n";
echo str_repeat('─', 60) . "\n\n";

foreach ($scripts as $name => $meta) {
    $ext   = pathinfo($name, PATHINFO_EXTENSION);
    $badge = $ext === 'sh' ? colorize('[bash]', 'yellow') : colorize('[php] ', 'green');
    echo "  $badge " . colorize($name, 'bold') . "\n";
    if ($meta['description']) {
        echo "         " . $meta['description'] . "\n";
    }
    if ($meta['usages']) {
        echo "         " . colorize('→ ', 'cyan') . $meta['usages'][0] . "\n";
    }
    echo "\n";
}

echo "  " . colorize('Détails : ', 'cyan') . "php scripts/help.php <nom-du-script>\n\n";
