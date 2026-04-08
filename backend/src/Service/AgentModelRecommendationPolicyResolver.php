<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Service;

use App\Enum\ConnectorType;
use App\ValueObject\AgentModelInfo;

/**
 * Resolves the recommended model for a connector from configuration-driven preference policies.
 */
class AgentModelRecommendationPolicyResolver
{
    /**
     * Stores the per-connector recommendation patterns used to pick a default model.
     *
     * @param array<string, list<string>> $policies
     */
    public function __construct(private readonly array $policies) {}

    /**
     * Selects the recommended model id for one connector from the configured preference patterns.
     *
     * @param AgentModelInfo[] $models
     */
    public function recommend(ConnectorType $connector, array $models): ?string
    {
        if ($models === []) {
            return null;
        }

        $patterns = $this->policies[$connector->value] ?? [];

        foreach ($patterns as $pattern) {
            $normalizedPattern = strtolower($pattern);

            foreach ($models as $model) {
                if (fnmatch($normalizedPattern, strtolower($model->id))) {
                    return $model->id;
                }
            }
        }

        return $models[0]->id;
    }
}
