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
 * Command for unassigning a feature from an agent.
 */
final class BacklogFeatureUnassignCommand extends AbstractBacklogCommand
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
        $board = $this->loadBoard();
        $match = isset($commandArgs[0])
            ? $this->entryResolver->requireFeature($board, $commandArgs[0])
            : $this->entryResolver->requireSingleFeatureForAgent($board, $agent);
        
        $entry = $match->getEntry();
        $feature = $entry->getFeature() ?? '';
        $actorAgent = $actorRole === BacklogPermissionService::ROLE_DEVELOPER ? $this->permissionService->requireWorkflowAgent() : null;

        $this->permissionService->assertCanUnassignFeature($actorRole, $actorAgent, $agent, $feature, $entry);
        if ($entry->getAgent() !== $agent) {
            throw new \RuntimeException("Feature {$feature} is not assigned to agent {$agent}.");
        }

        $entry->setAgent(null);
        $this->saveBoard($board, BacklogCommandName::FEATURE_UNASSIGN->value);
        $cleaned = $this->worktreeManager->cleanupAbandonedManagedWorktrees($board);

        $this->presenter->displaySuccess(sprintf('Unassigned feature %s from %s', $feature, $agent));
        if ($cleaned > 0) {
            $this->presenter->displayLine(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }
    }
}
