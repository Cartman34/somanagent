<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Service;

use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Model\ResolvedModel;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves canonical model tier and effort options into concrete client CLI arguments.
 */
final class AgentModelResolver
{
    private const TIERS = ['economy', 'balanced', 'premium'];
    private const EFFORTS = ['low', 'medium', 'high'];

    /**
     * Absolute path of the YAML mapping file.
     */
    private string $mappingPath;

    /**
     * @param string $mappingPath Absolute path of scripts/resources/backlog-agent/model-mapping.yaml
     */
    public function __construct(string $mappingPath)
    {
        $this->mappingPath = $mappingPath;
    }

    /**
     * Resolves the client CLI args from role defaults and optional CLI overrides.
     */
    public function resolve(
        AgentClient $client,
        AgentRole $role,
        ?string $tierOverride,
        ?string $effortOverride,
        ?string $modelOverride,
    ): ResolvedModel {
        if ($tierOverride !== null && $modelOverride !== null) {
            throw new \RuntimeException('--tier and --model are mutually exclusive.');
        }

        [$defaultTier, $defaultEffort] = $this->defaultsForRole($role);
        $tier = $tierOverride ?? $defaultTier;
        $effort = $effortOverride ?? $defaultEffort;

        $this->assertAllowed('tier', $tier, self::TIERS);
        $this->assertAllowed('effort', $effort, self::EFFORTS);

        if ($modelOverride !== null && trim($modelOverride) === '') {
            throw new \RuntimeException('--model cannot be empty.');
        }

        $clientConfig = $this->clientConfig($client);
        $warnings = $this->effortWarnings($client, $clientConfig, $effortOverride);

        if ($modelOverride !== null) {
            $args = ['--model', $modelOverride];
            if (($clientConfig['effort_supported'] ?? false) === true) {
                $args = array_merge($args, $this->effortArgsFromCell($this->matrixCell($client, $clientConfig, $tier, $effort)));
            }

            return new ResolvedModel($args, $warnings);
        }

        return new ResolvedModel($this->matrixCell($client, $clientConfig, $tier, $effort), $warnings);
    }

    /**
     * Returns the default canonical tier and effort for a role.
     *
     * @return array{0: string, 1: string}
     */
    private function defaultsForRole(AgentRole $role): array
    {
        return match ($role) {
            AgentRole::DEVELOPER, AgentRole::REVIEWER => ['balanced', 'medium'],
            AgentRole::MANAGER => ['premium', 'medium'],
        };
    }

    /**
     * @param list<string> $allowed
     */
    private function assertAllowed(string $label, string $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \RuntimeException(sprintf(
                "Invalid %s '%s'. Allowed values: %s.",
                $label,
                $value,
                implode(', ', $allowed),
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function clientConfig(AgentClient $client): array
    {
        if (!is_file($this->mappingPath)) {
            throw new \RuntimeException(sprintf('Model mapping file not found: %s', $this->mappingPath));
        }

        $data = Yaml::parseFile($this->mappingPath);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Model mapping file must contain a YAML object: %s', $this->mappingPath));
        }

        $clients = $data['clients'] ?? null;
        if (!is_array($clients)) {
            throw new \RuntimeException('Model mapping file must define a clients object.');
        }

        $clientConfig = $clients[$client->value] ?? null;
        if (!is_array($clientConfig)) {
            throw new \RuntimeException(sprintf("Model mapping for client '%s' is missing.", $client->value));
        }

        return $clientConfig;
    }

    /**
     * @param array<string, mixed> $clientConfig
     * @return list<string>
     */
    private function matrixCell(AgentClient $client, array $clientConfig, string $tier, string $effort): array
    {
        $matrix = $clientConfig['matrix'] ?? null;
        if (!is_array($matrix)) {
            throw new \RuntimeException(sprintf("Model mapping for client '%s' must define a matrix.", $client->value));
        }

        $cell = $matrix[$tier][$effort] ?? null;
        if (!is_array($cell) || !array_is_list($cell)) {
            throw new \RuntimeException(sprintf(
                "Model mapping for client '%s' is missing cell %s/%s.",
                $client->value,
                $tier,
                $effort,
            ));
        }

        foreach ($cell as $arg) {
            if (!is_string($arg) || $arg === '') {
                throw new \RuntimeException(sprintf(
                    "Model mapping for client '%s' cell %s/%s must contain only non-empty strings.",
                    $client->value,
                    $tier,
                    $effort,
                ));
            }
        }

        return $cell;
    }

    /**
     * Extracts effort-only CLI args from a full matrix cell.
     *
     * @param list<string> $cell
     * @return list<string>
     */
    private function effortArgsFromCell(array $cell): array
    {
        $args = $cell;
        for ($i = 0; $i < count($args) - 1; $i++) {
            if ($args[$i] === '--model') {
                array_splice($args, $i, 2);
                break;
            }
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $clientConfig
     * @return list<string>
     */
    private function effortWarnings(AgentClient $client, array $clientConfig, ?string $effortOverride): array
    {
        if (($clientConfig['effort_supported'] ?? false) === true || $effortOverride === null || $effortOverride === 'medium') {
            return [];
        }

        return [sprintf(
            "effort '%s' is not supported by client '%s'; the option is ignored.",
            $effortOverride,
            $client->value,
        )];
    }
}
