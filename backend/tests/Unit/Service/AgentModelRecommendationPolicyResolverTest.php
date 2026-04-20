<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\ConnectorType;
use App\Service\AgentModelRecommendationPolicyResolver;
use App\Tests\Support\LocalUnitTestCase;
use App\ValueObject\AgentModelInfo;

/**
 * Covers the recommendation policy matching used to pick one default model per connector.
 */
final class AgentModelRecommendationPolicyResolverTest extends LocalUnitTestCase
{
    /**
     * Prefers the first configured pattern match for the current connector.
     */
    public function testRecommendReturnsFirstModelMatchingConfiguredPattern(): void
    {
        $resolver = new AgentModelRecommendationPolicyResolver([
            ConnectorType::CodexCli->value => ['gpt-5*', 'o4*'],
        ]);

        $recommended = $resolver->recommend(ConnectorType::CodexCli, [
            new AgentModelInfo('o4-mini', 'o4-mini'),
            new AgentModelInfo('gpt-5.4', 'gpt-5.4'),
        ]);

        self::assertSame('gpt-5.4', $recommended);
    }

    /**
     * Falls back to the first available model when no configured pattern matches.
     */
    public function testRecommendFallsBackToFirstAvailableModelWithoutMatch(): void
    {
        $resolver = new AgentModelRecommendationPolicyResolver([
            ConnectorType::ClaudeCli->value => ['claude-3*'],
        ]);

        $recommended = $resolver->recommend(ConnectorType::CodexCli, [
            new AgentModelInfo('o4-mini', 'o4-mini'),
            new AgentModelInfo('gpt-5.4', 'gpt-5.4'),
        ]);

        self::assertSame('o4-mini', $recommended);
    }

    /**
     * Returns null when the connector exposes no candidate model.
     */
    public function testRecommendReturnsNullWhenNoModelIsAvailable(): void
    {
        $resolver = new AgentModelRecommendationPolicyResolver([]);

        self::assertNull($resolver->recommend(ConnectorType::CodexCli, []));
    }
}
