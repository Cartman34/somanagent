<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

use Sowapps\SoManAgent\Script\Client\Agent\AbstractAgentAuthManager;
use Sowapps\SoManAgent\Script\Client\Agent\Codex\CodexAuthManager;

/**
 * Codex auth management script runner.
 *
 * Manages Codex CLI auth with WSL as the source of truth and syncs it to Docker.
 */
final class CodexAuthRunner extends AbstractAgentAuthRunner
{
    private const NAME = 'codex-auth';

    public function __construct(
        private readonly CodexAuthManager $manager,
    ) {
        parent::__construct();
    }

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function getAgentLabel(): string
    {
        return 'Codex';
    }

    protected function getManager(): AbstractAgentAuthManager
    {
        return $this->manager;
    }

    protected function getDescription(): string
    {
        return 'Manage Codex CLI auth with WSL as the source of truth and sync it to Docker';
    }

    protected function getCommands(): array
    {
        return [
            ['name' => 'status', 'description' => 'Show current auth status'],
            ['name' => 'sync', 'description' => 'Sync auth from WSL to Docker'],
            ['name' => 'login', 'description' => 'Login with ChatGPT and sync'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/codex-auth.php status',
            'php scripts/codex-auth.php sync',
            'php scripts/codex-auth.php sync --force',
            'php scripts/codex-auth.php login',
            'php scripts/codex-auth.php login --force',
        ];
    }
}
