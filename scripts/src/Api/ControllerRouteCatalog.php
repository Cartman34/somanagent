<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Api;

/**
 * Extracts REST routes from Symfony controllers by parsing #[Route] attributes.
 */
final class ControllerRouteCatalog
{
    /**
     * Returns every parsed controller route with method, path, controller, and action.
     *
     * @return list<array{method: string, path: string, controller: string, action: string}>
     */
    public function listRoutes(string $controllerDirectory): array
    {
        if (!is_dir($controllerDirectory)) {
            throw new \RuntimeException("Directory not found: $controllerDirectory");
        }

        $routes = [];

        foreach (glob($controllerDirectory . '/*.php') as $file) {
            $content         = file_get_contents($file);
            $controllerClass = basename($file, '.php');

            if ($content === false) {
                throw new \RuntimeException("Unable to read controller file: $file");
            }

            $prefix    = '';
            $seenClass = false;
            $pending   = [];

            foreach (explode("\n", $content) as $line) {
                $trimmedLine = trim($line);

                if (preg_match('/#\[Route\(\s*[\'"]([^\'"]*)[\'"]/', $trimmedLine, $pathMatches) === 1) {
                    $methods = [];

                    if (preg_match('/methods:\s*\[([^\]]+)\]/', $trimmedLine, $methodMatches) === 1) {
                        foreach (explode(',', $methodMatches[1]) as $method) {
                            $methods[] = strtoupper(trim(trim($method, " '\"")));
                        }
                    }

                    if (!$seenClass) {
                        $prefix = $pathMatches[1];
                    } else {
                        $pending[] = [
                            'path' => $pathMatches[1],
                            'methods' => $methods !== [] ? $methods : ['ANY'],
                        ];
                    }

                    continue;
                }

                if (!$seenClass && preg_match('/^(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/', $trimmedLine) === 1) {
                    $seenClass = true;
                    continue;
                }

                if ($seenClass && $pending !== [] && preg_match('/public\s+function\s+(\w+)\s*\(/', $trimmedLine, $functionMatches) === 1) {
                    foreach ($pending as $pendingRoute) {
                        $fullPath = rtrim($prefix, '/') . $pendingRoute['path'];

                        $routes[] = [
                            'method' => implode('|', $pendingRoute['methods']),
                            'path' => $fullPath !== '' ? $fullPath : '/',
                            'controller' => $controllerClass,
                            'action' => $functionMatches[1],
                        ];
                    }

                    $pending = [];
                    continue;
                }

                if (
                    $seenClass
                    && $pending !== []
                    && $trimmedLine !== ''
                    && !str_starts_with($trimmedLine, '#[')
                    && !str_starts_with($trimmedLine, '//')
                    && !str_starts_with($trimmedLine, '*')
                    && !in_array($trimmedLine, ['{', '}'], true)
                ) {
                    $pending = [];
                }
            }
        }

        return $routes;
    }
}
