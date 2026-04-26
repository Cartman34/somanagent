<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\BacklogPresenter;
use SoManAgent\Script\Backlog\BacklogPermissionService;

/**
 * Command for assigning a feature to an agent.
 */
final class BacklogFeatureAssignCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogWorktreeManager $worktreeManager;

    private BacklogPermissionService $permissionService;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogWorktreeManager $worktreeManager,
        BacklogPermissionService $permissionService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->worktreeManager = $worktreeManager;
        $this->permissionService = $permissionService;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $actorRole = $this->permissionService->requireWorkflowRole();
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        if (!isset($commandArgs[0])) {
            throw new \RuntimeException('feature-assign requires <feature>.');
        }
        $feature = $commandArgs[0];
        $board = $this->loadBoard();
        $actorAgent = $actorRole === BacklogPermissionService::ROLE_DEVELOPER ? $this->permissionService->requireWorkflowAgent() : null;

        $this->permissionService->assertCanAssignFeature($actorRole, $actorAgent, $agent, $feature, $board, $this->entryResolver);

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

        $this->presenter->displaySuccess(sprintf('Assigned feature %s to %s', $feature, $agent));
        if ($cleaned > 0) {
            $this->presenter->displayLine(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }
    }
}
