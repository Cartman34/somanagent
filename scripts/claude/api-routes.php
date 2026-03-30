<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

/**
 * Lists all REST routes from Symfony controllers.
 *
 * Usage: php scripts/claude/api-routes.php [--json]
 *
 * Parses #[Route(...)] attributes without booting Symfony.
 * Output: [METHOD] /path  →  ControllerClass::method
 */

$jsonMode      = in_array('--json', $argv ?? [], true);
$root          = realpath(__DIR__ . '/../../');
$controllerDir = $root . '/backend/src/Controller';

if (!is_dir($controllerDir)) {
    fwrite(STDERR, "Directory not found: $controllerDir\n");
    exit(1);
}

$routes = [];

foreach (glob($controllerDir . '/*.php') as $file) {
    $content         = file_get_contents($file);
    $controllerClass = basename($file, '.php');

    $prefix    = '';   // Class-level route prefix
    $seenClass = false;
    $pending   = [];   // [['path' => ..., 'methods' => [...]]]

    foreach (explode("\n", $content) as $line) {
        $t = trim($line);

        // #[Route(...)] attribute
        if (preg_match('/#\[Route\(\s*[\'"]([^\'"]*)[\'"]/', $t, $pathM)) {
            $methods = [];
            if (preg_match('/methods:\s*\[([^\]]+)\]/', $t, $mM)) {
                foreach (explode(',', $mM[1]) as $m) {
                    $methods[] = strtoupper(trim(trim($m, " '\"")));
                }
            }

            if (!$seenClass) {
                // Before class declaration = global prefix
                $prefix = $pathM[1];
            } else {
                $pending[] = ['path' => $pathM[1], 'methods' => $methods ?: ['ANY']];
            }
            continue;
        }

        // Class declaration
        if (!$seenClass && preg_match('/^(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/', $t)) {
            $seenClass = true;
            continue;
        }

        // Public method → attach pending routes
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

        // Reset pending on significant non-attribute line
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

// ── Output ───────────────────────────────────────────────────────────────────

if ($jsonMode) {
    echo json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
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
