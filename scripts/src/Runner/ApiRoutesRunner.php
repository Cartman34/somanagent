<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Lists all REST routes from Symfony controllers by parsing #[Route] attributes.
 */
final class ApiRoutesRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'List all REST routes from Symfony controllers by parsing #[Route] attributes';
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--json', 'description' => 'Output routes as JSON'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/claude/api-routes.php',
            'php scripts/claude/api-routes.php --json',
        ];
    }

    public function run(array $args): int
    {
        $jsonMode = in_array('--json', $args, true);
        $controllerDir = $this->projectRoot . '/backend/src/Controller';

        if (!is_dir($controllerDir)) {
            $this->console->fail("Directory not found: $controllerDir");
        }

        $routes = [];

        foreach (glob($controllerDir . '/*.php') as $file) {
            $content         = file_get_contents($file);
            $controllerClass = basename($file, '.php');

            $prefix    = '';
            $seenClass = false;
            $pending   = [];

            foreach (explode("\n", $content) as $line) {
                $t = trim($line);

                if (preg_match('/#\[Route\(\s*[\'"]([^\'"]*)[\'"]/', $t, $pathM)) {
                    $methods = [];
                    if (preg_match('/methods:\s*\[([^\]]+)\]/', $t, $mM)) {
                        foreach (explode(',', $mM[1]) as $m) {
                            $methods[] = strtoupper(trim(trim($m, " '\"")));
                        }
                    }

                    if (!$seenClass) {
                        $prefix = $pathM[1];
                    } else {
                        $pending[] = ['path' => $pathM[1], 'methods' => $methods ?: ['ANY']];
                    }
                    continue;
                }

                if (!$seenClass && preg_match('/^(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/', $t)) {
                    $seenClass = true;
                    continue;
                }

                if ($seenClass && !empty($pending) && preg_match('/public\s+function\s+(\w+)\s*\(/', $t, $funcM)) {
                    foreach ($pending as $p) {
                        $fullPath = rtrim($prefix, '/') . $p['path'];
                        $routes[] = [
                            'method'     => implode('|', $p['methods']),
                            'path'       => $fullPath ?: '/',
                            'controller' => $controllerClass,
                            'action'     => $funcM[1],
                        ];
                    }
                    $pending = [];
                    continue;
                }

                if ($seenClass && !empty($pending) && !empty($t)
                    && !str_starts_with($t, '#[')
                    && !str_starts_with($t, '//')
                    && !str_starts_with($t, '*')
                    && !in_array($t, ['{', '}'])
                ) {
                    $pending = [];
                }
            }
        }

        if ($jsonMode) {
            echo json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            return 0;
        }

        usort($routes, fn($a, $b) => strcmp($a['path'] . $a['method'], $b['path'] . $b['method']));

        $prevController = null;
        foreach ($routes as $r) {
            if ($r['controller'] !== $prevController) {
                echo "\n── {$r['controller']} ──\n";
                $prevController = $r['controller'];
            }
            $pad = str_pad("[{$r['method']}]", 12);
            echo "  {$pad} {$r['path']}  →  ::{$r['action']}\n";
        }
        echo "\n";

        return 0;
    }
}
