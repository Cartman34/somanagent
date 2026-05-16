<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Service\AgentModelResolver;

/**
 * Unit tests for canonical tier/effort resolution into client CLI arguments.
 */
final class AgentModelResolverTest
{
    /**
     * Runs every test case and returns the cumulative number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testDefaultsByRole();
        $failed += $this->testTierAndEffortOverrides();
        $failed += $this->testModelOverrideKeepsResolvedEffortForSupportedClients();
        $failed += $this->testRejectsTierAndModelTogether();
        $failed += $this->testUnsupportedEffortWarningKeepsModelOnlyArgs();

        return $failed;
    }

    private function testDefaultsByRole(): int
    {
        $resolver = $this->resolver();

        $developer = $resolver->resolve(AgentClient::CLAUDE, AgentRole::DEVELOPER, null, null, null);
        $manager = $resolver->resolve(AgentClient::CLAUDE, AgentRole::MANAGER, null, null, null);

        if ($developer->cliArgs !== ['--model', 'sonnet', '--effort', 'medium']) {
            echo "FAIL testDefaultsByRole: unexpected developer args " . json_encode($developer->cliArgs) . "\n";
            return 1;
        }
        if ($manager->cliArgs !== ['--model', 'opus', '--effort', 'medium']) {
            echo "FAIL testDefaultsByRole: unexpected manager args " . json_encode($manager->cliArgs) . "\n";
            return 1;
        }

        echo "OK testDefaultsByRole\n";
        return 0;
    }

    private function testTierAndEffortOverrides(): int
    {
        $resolved = $this->resolver()->resolve(AgentClient::CODEX, AgentRole::DEVELOPER, 'economy', 'high', null);
        $expected = ['--model', 'gpt-5.4-mini', '--config', 'model_reasoning_effort="high"'];

        if ($resolved->cliArgs !== $expected || $resolved->warnings !== []) {
            echo "FAIL testTierAndEffortOverrides: expected " . json_encode($expected)
                . ', got ' . json_encode($resolved->cliArgs) . ' warnings ' . json_encode($resolved->warnings) . "\n";
            return 1;
        }

        echo "OK testTierAndEffortOverrides\n";
        return 0;
    }

    private function testModelOverrideKeepsResolvedEffortForSupportedClients(): int
    {
        $resolved = $this->resolver()->resolve(AgentClient::CLAUDE, AgentRole::DEVELOPER, null, 'high', 'raw-model');
        $expected = ['--model', 'raw-model', '--effort', 'high'];

        if ($resolved->cliArgs !== $expected) {
            echo "FAIL testModelOverrideKeepsResolvedEffortForSupportedClients: expected " . json_encode($expected)
                . ', got ' . json_encode($resolved->cliArgs) . "\n";
            return 1;
        }

        echo "OK testModelOverrideKeepsResolvedEffortForSupportedClients\n";
        return 0;
    }

    private function testRejectsTierAndModelTogether(): int
    {
        $threw = false;
        try {
            $this->resolver()->resolve(AgentClient::CLAUDE, AgentRole::DEVELOPER, 'economy', null, 'raw-model');
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), '--tier and --model are mutually exclusive');
        }

        if (!$threw) {
            echo "FAIL testRejectsTierAndModelTogether: expected mutual exclusion error\n";
            return 1;
        }

        echo "OK testRejectsTierAndModelTogether\n";
        return 0;
    }

    private function testUnsupportedEffortWarningKeepsModelOnlyArgs(): int
    {
        $resolved = $this->resolver()->resolve(AgentClient::GEMINI, AgentRole::DEVELOPER, null, 'high', null);
        $expected = ['--model', 'gemini-2.5-flash'];

        if ($resolved->cliArgs !== $expected) {
            echo "FAIL testUnsupportedEffortWarningKeepsModelOnlyArgs: expected " . json_encode($expected)
                . ', got ' . json_encode($resolved->cliArgs) . "\n";
            return 1;
        }
        if ($resolved->warnings !== ["effort 'high' is not supported by client 'gemini'; the option is ignored."]) {
            echo "FAIL testUnsupportedEffortWarningKeepsModelOnlyArgs: unexpected warnings "
                . json_encode($resolved->warnings) . "\n";
            return 1;
        }

        echo "OK testUnsupportedEffortWarningKeepsModelOnlyArgs\n";
        return 0;
    }

    private function resolver(): AgentModelResolver
    {
        return new AgentModelResolver(dirname(__DIR__, 4) . '/resources/backlog-agent/model-mapping.yaml');
    }
}
