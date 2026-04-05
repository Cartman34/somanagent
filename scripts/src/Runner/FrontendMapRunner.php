<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Outputs a map of the React frontend: routes, pages, and API clients.
 */
final class FrontendMapRunner extends AbstractScriptRunner
{
    protected function getDescription(): string
    {
        return 'Output a map of the React frontend: routes, pages, and API clients';
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--json', 'description' => 'Output map as JSON'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/claude/frontend-map.php',
            'php scripts/claude/frontend-map.php --json',
        ];
    }

    public function run(array $args): int
    {
        $jsonMode = in_array('--json', $args, true);
        $frontendSrc = $this->projectRoot . '/frontend/src';

        $appFile = $frontendSrc . '/App.tsx';
        $routes  = [];

        if (file_exists($appFile)) {
            $content = file_get_contents($appFile);

            preg_match_all('/<Route\s+path="([^"]+)"\s+element=\{<(\w+)(?:\s*\/)?>/', $content, $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $routes[] = ['path' => '/' . ltrim($match[1], '/'), 'component' => $match[2]];
            }

            preg_match_all('/<Route\s+index\s+element=\{<Navigate\s+to="([^"]+)"/', $content, $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $routes[] = ['path' => '/ (index)', 'component' => 'Navigate → ' . $match[1]];
            }
        }

        $pages    = [];
        $pagesDir = $frontendSrc . '/pages';

        if (is_dir($pagesDir)) {
            foreach (glob($pagesDir . '/*.tsx') as $file) {
                $name = basename($file, '.tsx');
                $src  = file_get_contents($file);

                $apis = [];
                preg_match_all("/from\s+['\"](?:@\/api\/|\.\.\/api\/|\.\/api\/)([^'\"]+)['\"]/", $src, $am);
                foreach ($am[1] as $api) {
                    $apis[] = $api;
                }

                $matchedRoute = '';
                foreach ($routes as $r) {
                    if (str_contains($r['component'], $name)) {
                        $matchedRoute = $r['path'];
                        break;
                    }
                }

                $pages[] = ['name' => $name, 'route' => $matchedRoute, 'apis' => $apis];
            }
            usort($pages, fn($a, $b) => strcmp($a['name'], $b['name']));
        }

        $apiClients = [];
        $apiDir     = $frontendSrc . '/api';

        if (is_dir($apiDir)) {
            foreach (glob($apiDir . '/*.ts') as $file) {
                $name = basename($file, '.ts');
                if ($name === 'client') {
                    continue;
                }
                $src = file_get_contents($file);

                $endpoints = [];
                preg_match_all("/apiClient\.(get|post|put|patch|delete)\s*\(\s*[`'\"]([^`'\"]+)[`'\"]/" , $src, $em, PREG_SET_ORDER);
                foreach ($em as $e) {
                    $endpoints[] = strtoupper($e[1]) . ' ' . $e[2];
                }

                $types = [];
                preg_match_all('/export\s+(?:interface|type)\s+(\w+)/', $src, $tm);
                $types = $tm[1] ?? [];

                $apiClients[] = [
                    'module'    => $name,
                    'endpoints' => array_unique($endpoints),
                    'types'     => $types,
                ];
            }
            usort($apiClients, fn($a, $b) => strcmp($a['module'], $b['module']));
        }

        if ($jsonMode) {
            echo json_encode(compact('routes', 'pages', 'apiClients'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            return 0;
        }

        echo "\n── ROUTES (App.tsx) ──\n";
        foreach ($routes as $r) {
            $pad = str_pad($r['path'], 22);
            echo "  {$pad} → {$r['component']}\n";
        }

        echo "\n── PAGES ──\n";
        foreach ($pages as $p) {
            $route = $p['route'] ? " [{$p['route']}]" : '';
            echo "  {$p['name']}{$route}\n";
            if ($p['apis']) {
                echo "    api: " . implode(', ', $p['apis']) . "\n";
            }
        }

        echo "\n── API CLIENTS ──\n";
        foreach ($apiClients as $c) {
            echo "  {$c['module']}";
            if ($c['types']) {
                echo '  types: ' . implode(', ', $c['types']);
            }
            echo "\n";
            foreach ($c['endpoints'] as $ep) {
                echo "    {$ep}\n";
            }
        }

        echo "\n";

        return 0;
    }
}
