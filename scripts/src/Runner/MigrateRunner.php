<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\WorktreeScriptProxy;

/**
 * Migrate script runner.
 *
 * Runs Doctrine migrations inside the PHP container.
 * With --generate, produces an isolated diff against a temporary database
 * so that the shared application database is never used as the diff target.
 */
final class MigrateRunner extends AbstractScriptRunner
{
    public const NAME = 'migrate';

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function getDescription(): string
    {
        return 'Run Doctrine migrations inside the PHP container';
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--dry-run', 'description' => 'Show SQL queries without executing'],
            ['name' => '--generate', 'description' => 'Generate a new migration from the current entity diff using an isolated temporary database'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/migrate.php',
            'php scripts/migrate.php --dry-run',
            'php scripts/migrate.php --generate',
        ];
    }

    /**
     * Runs Doctrine migrations or generates a diff, depending on the flags.
     *
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        [, $options] = $this->parseArgs($args);

        if (isset($options['generate'])) {
            $agentCode = $this->detectAgentCode();
            $boardRoot = $this->detectBoardRoot();
            return (new MigrateGenerateService($this->app, $agentCode, $this->projectRoot, $boardRoot))->run();
        }

        try {
            return (new DoctrineRunner($this->app))->run(['migrate', ...$args]);
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->console->fail($e->getMessage());
        }
    }

    /**
     * Resolves the agent code for the current execution context.
     *
     * When running inside a linked WA the agent code is extracted from the
     * last path segment of the WA root (e.g. ".agent-worktrees/d04" → "d04").
     * Outside a linked WA the SOMANAGER_AGENT env var is used as fallback,
     * and "main" is returned when neither is available.
     */
    private function detectAgentCode(): string
    {
        // SOMANAGER_AGENT is checked first so that an explicit caller context
        // (workflow prefix, test runner) always wins over the WA path heuristic.
        // WA-path detection is only the fallback for interactive use without the prefix.
        $fromEnv = trim((string) getenv('SOMANAGER_AGENT'));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        if ($this->scriptFile !== null) {
            try {
                $context = WorktreeScriptProxy::detect($this->scriptFile);
                if ($context->isLinkedWorktree()) {
                    return basename($context->getCurrentRoot());
                }
            } catch (\RuntimeException) {
                // Not in git repo or path cannot be resolved — fall through
            }
        }

        return 'main';
    }

    /**
     * Resolves the WP (main worktree) root where the canonical backlog board lives.
     *
     * When running inside a linked WA, returns the main worktree root so that
     * board reads target the live board in WP, not the WA copy.
     * Falls back to $projectRoot when the script is not in a linked worktree.
     */
    private function detectBoardRoot(): string
    {
        if ($this->scriptFile !== null) {
            try {
                $context = WorktreeScriptProxy::detect($this->scriptFile);
                if ($context->isLinkedWorktree()) {
                    return $context->getMainRoot();
                }
            } catch (\RuntimeException) {
                // Not in git repo or path cannot be resolved — fall through
            }
        }

        return $this->projectRoot;
    }
}
