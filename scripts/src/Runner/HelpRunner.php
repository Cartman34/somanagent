<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Help script runner.
 *
 * Lists all available scripts with their description and usage examples.
 */
final class HelpRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'List all available scripts with their description and usage examples';
    }

    protected function getArguments(): array
    {
        return [
            ['name' => '<script-name>', 'description' => 'Show details for a specific script'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/help.php',
            'php scripts/help.php dev.php',
        ];
    }

    public function run(array $args): int
    {
        $scriptsDir = "{$this->projectRoot}/scripts";

        $scripts = [];
        foreach (scandir($scriptsDir) as $file) {
            if ($file === '.' || $file === '..') continue;
            $fullPath = "$scriptsDir/$file";
            if (!is_file($fullPath)) continue;
            if (!in_array(pathinfo($file, PATHINFO_EXTENSION), ['php', 'sh'], true)) continue;
            $scripts[$file] = $this->parseHeader($fullPath);
        }
        ksort($scripts);

        if ($args !== []) {
            $search = $args[0];
            $found  = false;
            foreach ($scripts as $name => $meta) {
                if ($name === $search || $name === "$search.php" || $name === "$search.sh") {
                    echo $this->ansi($name, 'bold') . "\n";
                    echo '  ' . $this->ansi($meta['description'], 'cyan') . "\n\n";
                    foreach ($meta['usages'] as $usage) {
                        echo '  ' . $this->ansi('$', 'green') . " $usage\n";
                    }
                    $found = true;
                }
            }
            if (!$found) {
                $this->console->fail("Script \"$search\" not found.");
            }
            return 0;
        }

        echo "\n" . $this->ansi('SoManAgent — Available scripts', 'bold') . "\n";
        echo str_repeat('─', 60) . "\n\n";

        foreach ($scripts as $name => $meta) {
            $ext   = pathinfo($name, PATHINFO_EXTENSION);
            $badge = $ext === 'sh' ? $this->ansi('[bash]', 'yellow') : $this->ansi('[php] ', 'green');
            echo "  $badge " . $this->ansi($name, 'bold') . "\n";
            if ($meta['description']) {
                echo "         {$meta['description']}\n";
            }
            if ($meta['usages']) {
                echo '         ' . $this->ansi('→ ', 'cyan') . $meta['usages'][0] . "\n";
            }
            echo "\n";
        }

        echo '  ' . $this->ansi('Details: ', 'cyan') . "php scripts/help.php <script-name>\n\n";
        return 0;
    }

    /**
     * Parse the header annotations of a script file.
     *
     * @return array{description: string, usages: string[]}
     */
    private function parseHeader(string $file): array
    {
        $lines       = file($file, FILE_IGNORE_NEW_LINES);
        $description = '';
        $usages      = [];

        $inPhpDoc = false;
        foreach ($lines as $i => $line) {
            if ($i === 0 && str_starts_with($line, '#!')) continue;
            if (trim($line) === '<?php') continue;

            if (str_starts_with(trim($line), '/**')) { $inPhpDoc = true; continue; }
            if ($inPhpDoc) { if (str_contains($line, '*/')) { $inPhpDoc = false; } continue; }

            $content = null;
            if (str_starts_with($line, '// ')) {
                $content = substr($line, 3);
            } elseif (str_starts_with($line, '# ')) {
                $content = substr($line, 2);
            } elseif ($line === '#' || $line === '//') {
                continue;
            } else {
                break;
            }

            if (str_starts_with($content, 'Description: ')) {
                $description = substr($content, 13);
            } elseif (str_starts_with($content, 'Usage: ')) {
                $usages[] = substr($content, 7);
            }
        }

        return ['description' => $description, 'usages' => $usages];
    }

    private function ansi(string $text, string $color): string
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
}
