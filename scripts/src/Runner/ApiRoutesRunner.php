<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Api\ControllerRouteCatalog;

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

    /**
     * Prints the current REST route catalog in human-readable or JSON format.
     *
     * @param array<string> $args Raw CLI arguments after the script name
     */
    public function run(array $args): int
    {
        $jsonMode = in_array('--json', $args, true);
        $controllerDir = $this->projectRoot . '/backend/src/Controller';
        $routeCatalog = new ControllerRouteCatalog();

        try {
            $routes = $routeCatalog->listRoutes($controllerDir);
        } catch (\RuntimeException $exception) {
            $this->console->fail($exception->getMessage());
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
