<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Console;

/**
 * Command for assigning a feature to an agent.
 */
final class BacklogFeatureAssignCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogWorktreeManager $worktreeManager;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogWorktreeManager $worktreeManager
    ) {
        parent::__construct($console, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->worktreeManager = $worktreeManager;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $actorRole = $this->requireWorkflowRole();
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        if (!isset($commandArgs[0])) {
            throw new \RuntimeException('feature-assign requires <feature>.');
        }
        $feature = $commandArgs[0];
        $board = $this->loadBoard();
        $actorAgent = $actorRole === self::ROLE_DEVELOPER ? $this->requireWorkflowAgent() : null;

        $this->assertCanAssignFeature($actorRole, $actorAgent, $agent, $feature, $board, $this->entryResolver);

        if ($this->entryResolver->getSingleFeatureForAgent($board, $agent, false) !== null) {
            throw new \RuntimeException("Agent {$agent} already owns an active feature.");
        }

        $match = $this->entryResolver->requireFeature($board, $feature);
        $previousAgent = $match->getEntry()->getAgent();
        $match->getEntry()->setAgent($agent);
        $this->saveBoard($board, BacklogCommandName::FEATURE_ASSIGN->value);

        $worktree = $this->worktreeManager->prepareAgentWorktree($agent);
        $this->worktreeManager->checkoutBranchInWorktree($worktree, $match->getEntry()->getBranch() ?? '', false);
        $cleaned = $previousAgent !== null && $previousAgent !== $agent
            ? $this->worktreeManager->cleanupAbandonedManagedWorktrees($board)
            : 0;

        $this->console->ok(sprintf('Assigned feature %s to %s', $feature, $agent));
        if ($cleaned > 0) {
            $this->console->line(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }
    }
}
