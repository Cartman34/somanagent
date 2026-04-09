<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Validation;

use SoManAgent\Script\Api\ControllerRouteCatalog;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates the consistency between controller routes and the OpenAPI specification.
 *
 * The contract is intentionally spec-first:
 * - every implemented route must exist in the specification
 * - every documented operation marked as implemented must exist in the code
 * - planned operations may stay ahead of the code with `x-somanagent-implemented: false`
 */
final class OpenApiConsistencyValidator
{
    private const DOCUMENTED_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    public function __construct(
        private readonly ControllerRouteCatalog $controllerRouteCatalog = new ControllerRouteCatalog(),
    ) {}

    /**
     * Validates the OpenAPI specification against the current Symfony controllers.
     *
     * @return list<string> Human-readable validation errors. Empty means success.
     */
    public function validate(string $projectRoot): array
    {
        require_once $projectRoot . '/backend/vendor/autoload.php';

        $specificationPath = $projectRoot . '/doc/technical/openapi.yaml';
        $controllerDirectory = $projectRoot . '/backend/src/Controller';

        if (!is_file($specificationPath)) {
            return ["OpenAPI specification not found: $specificationPath"];
        }

        try {
            $decodedSpecification = Yaml::parseFile($specificationPath);
        } catch (\Throwable) {
            return ["OpenAPI specification is not valid YAML: $specificationPath"];
        }

        if (!is_array($decodedSpecification)) {
            return ["OpenAPI specification must decode to a top-level object: $specificationPath"];
        }

        if (!isset($decodedSpecification['paths']) || !is_array($decodedSpecification['paths'])) {
            return ['OpenAPI specification must define a top-level "paths" object.'];
        }

        $implementedRoutes = $this->normalizeImplementedRoutes(
            $this->controllerRouteCatalog->listRoutes($controllerDirectory),
        );
        [$documentedRoutes, $plannedRoutes, $documentationErrors] = $this->normalizeDocumentedRoutes($decodedSpecification['paths']);

        if ($documentationErrors !== []) {
            return $documentationErrors;
        }

        $errors = [];

        foreach ($implementedRoutes as $routeKey => $route) {
            if (!isset($documentedRoutes[$routeKey]) && !isset($plannedRoutes[$routeKey])) {
                $errors[] = sprintf(
                    'Implemented route missing from OpenAPI spec: %s %s (%s::%s)',
                    $route['method'],
                    $route['path'],
                    $route['controller'],
                    $route['action'],
                );
            }
        }

        foreach ($documentedRoutes as $routeKey => $route) {
            if (!isset($implementedRoutes[$routeKey])) {
                $errors[] = sprintf(
                    'OpenAPI operation marked as implemented but missing in code: %s %s',
                    $route['method'],
                    $route['path'],
                );
            }
        }

        sort($errors);

        return $errors;
    }

    /**
     * @param list<array{method: string, path: string, controller: string, action: string}> $routes
     * @return array<string, array{method: string, path: string, controller: string, action: string}>
     */
    private function normalizeImplementedRoutes(array $routes): array
    {
        $normalizedRoutes = [];

        foreach ($routes as $route) {
            foreach (explode('|', $route['method']) as $method) {
                $trimmedMethod = strtoupper(trim($method));

                if ($trimmedMethod === 'ANY') {
                    continue;
                }

                $normalizedRoutes[$this->buildRouteKey($trimmedMethod, $route['path'])] = [
                    'method' => $trimmedMethod,
                    'path' => $route['path'],
                    'controller' => $route['controller'],
                    'action' => $route['action'],
                ];
            }
        }

        return $normalizedRoutes;
    }

    /**
     * @param array<string, mixed> $paths
     * @return array{0: array<string, array{method: string, path: string}>, 1: array<string, array{method: string, path: string}>, 2: list<string>}
     */
    private function normalizeDocumentedRoutes(array $paths): array
    {
        $implementedRoutes = [];
        $plannedRoutes = [];
        $errors = [];

        foreach ($paths as $path => $pathItem) {
            if (!is_string($path) || !is_array($pathItem)) {
                $errors[] = 'OpenAPI "paths" entries must use string keys and object values.';
                continue;
            }

            foreach (self::DOCUMENTED_METHODS as $method) {
                if (!array_key_exists($method, $pathItem)) {
                    continue;
                }

                if (!is_array($pathItem[$method])) {
                    $errors[] = sprintf('OpenAPI operation %s %s must be an object.', strtoupper($method), $path);
                    continue;
                }

                $routeKey = $this->buildRouteKey(strtoupper($method), $path);
                $route = ['method' => strtoupper($method), 'path' => $path];
                $implemented = ($pathItem[$method]['x-somanagent-implemented'] ?? true) !== false;

                if ($implemented) {
                    $implementedRoutes[$routeKey] = $route;
                    continue;
                }

                $plannedRoutes[$routeKey] = $route;
            }
        }

        return [$implementedRoutes, $plannedRoutes, $errors];
    }

    private function buildRouteKey(string $method, string $path): string
    {
        return sprintf('%s %s', strtoupper($method), $path);
    }
}
