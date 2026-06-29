<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

use Sowapps\SoManAgent\Script\Client\Agent\AbstractAgentAuthManager;
use Sowapps\SoManAgent\Script\Client\Agent\OpenCode\OpenCodeAuthManager;

/**
 * OpenCode auth management script runner.
 *
 * Manages OpenCode provider credentials with WSL as the source of truth and syncs them to Docker.
 * Accepts an optional provider positional argument forwarded to the login command.
 */
final class OpenCodeAuthRunner extends AbstractAgentAuthRunner
{
    private const NAME = 'opencode-auth';

    public function __construct(
        private readonly OpenCodeAuthManager $manager,
    ) {
        parent::__construct();
    }

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function getAgentLabel(): string
    {
        return 'OpenCode';
    }

    protected function getManager(): AbstractAgentAuthManager
    {
        return $this->manager;
    }

    protected function getDescription(): string
    {
        return 'Manage OpenCode CLI provider credentials with WSL as the source of truth and sync them to Docker';
    }

    protected function getCommands(): array
    {
        return [
            ['name' => 'status', 'description' => 'Show current auth status'],
            ['name' => 'sync', 'description' => 'Sync auth from WSL to Docker'],
            ['name' => 'login', 'description' => 'Login to a provider and sync'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/opencode-auth.php status',
            'php scripts/opencode-auth.php sync',
            'php scripts/opencode-auth.php sync --force',
            'php scripts/opencode-auth.php login',
            'php scripts/opencode-auth.php login openrouter',
        ];
    }

    /**
     * Captures the optional provider positional before delegating to the shared dispatch.
     *
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        [$positional] = $this->parseArgs($args);

        // The provider is the optional positional after the command (e.g. "login openrouter").
        $this->manager->setProvider($positional[1] ?? null);

        return parent::run($args);
    }
}
