<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncherRegistry;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use Sowapps\SoManAgent\Script\Backlog\Agent\Exception\ClientNotInstalledException;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncher;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\AbstractAgentClientLauncher;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use Sowapps\SoManAgent\Script\Backlog\Agent\Model\ResolvedModel;


/**
 * Unit tests for AgentClientLauncherRegistry and AbstractAgentClientLauncher defaults.
 */
final class AgentClientLauncherRegistryTest
{
    /**
     * Runs all test cases and returns the total number of failures.
     */
    public function run(): int
    {
        $failed = 0;

        $failed += $this->testGetThrowsWhenEmpty();
        $failed += $this->testGetReturnsRegisteredLauncher();
        $failed += $this->testHas();
        $failed += $this->testAbstractDefaultsNoOpPrepare();
        $failed += $this->testAbstractDefaultsPassthroughEnv();

        return $failed;
    }

    private function testGetThrowsWhenEmpty(): int
    {
        $registry = new AgentClientLauncherRegistry();
        try {
            $registry->get(AgentClient::CLAUDE);
            echo "FAIL testGetThrowsWhenEmpty: expected ClientNotInstalledException\n";
            return 1;
        } catch (ClientNotInstalledException $e) {
            echo "OK testGetThrowsWhenEmpty\n";
            return 0;
        }
    }

    private function testGetReturnsRegisteredLauncher(): int
    {
        $registry = new AgentClientLauncherRegistry();
        $launcher = $this->makeStubLauncher(AgentClient::CLAUDE);
        $registry->register($launcher);

        $got = $registry->get(AgentClient::CLAUDE);
        if ($got !== $launcher) {
            echo "FAIL testGetReturnsRegisteredLauncher: got wrong launcher\n";
            return 1;
        }
        echo "OK testGetReturnsRegisteredLauncher\n";
        return 0;
    }

    private function testHas(): int
    {
        $registry = new AgentClientLauncherRegistry();
        $registry->register($this->makeStubLauncher(AgentClient::CODEX));

        if ($registry->has(AgentClient::CLAUDE)) {
            echo "FAIL testHas: should not have CLAUDE\n";
            return 1;
        }
        if (!$registry->has(AgentClient::CODEX)) {
            echo "FAIL testHas: should have CODEX\n";
            return 1;
        }
        echo "OK testHas\n";
        return 0;
    }

    private function testAbstractDefaultsNoOpPrepare(): int
    {
        $launcher = $this->makeStubLauncher(AgentClient::CLAUDE);
        // Should not throw or do anything
        $launcher->prepareWorktree('/fake', '/fake/ctx.md');
        echo "OK testAbstractDefaultsNoOpPrepare\n";
        return 0;
    }

    private function testAbstractDefaultsPassthroughEnv(): int
    {
        $launcher = $this->makeStubLauncher(AgentClient::CLAUDE);
        $env = ['FOO' => 'bar', 'BAZ' => 'qux'];
        $result = $launcher->buildEnvironment($env, '/fake/ctx.md');
        if ($result !== $env) {
            echo "FAIL testAbstractDefaultsPassthroughEnv: env modified by default\n";
            return 1;
        }
        echo "OK testAbstractDefaultsPassthroughEnv\n";
        return 0;
    }

    private function makeStubLauncher(AgentClient $client): AgentClientLauncher
    {
        return new class($client) extends AbstractAgentClientLauncher {
            /**
             * @param AgentClient $agentClient
             */
            public function __construct(private AgentClient $agentClient) {}

            /**
             * {@inheritdoc}
             */
            public function client(): AgentClient
            {
                return $this->agentClient;
            }

            /**
             * {@inheritdoc}
             */
            public function isAvailable(): bool
            {
                return true;
            }

            /**
             * {@inheritdoc}
             */
            public function buildLaunchCommand(
                string $worktree,
                string $contextFilePath,
                AgentRole $role,
                ?string $resumeSessionId = null,
                bool $continueLast = false,
                ?ResolvedModel $resolvedModel = null,
                ?string $initialPrompt = null,
            ): array
            {
                return ['echo', []];
            }

            /**
             * {@inheritdoc}
             */
            public function captureCurrentSessionId(string $worktree): ?string
            {
                return null;
            }

            /**
             * {@inheritdoc}
             */
            public function listSessions(string $worktree): array
            {
                return [];
            }
        };
    }
}
