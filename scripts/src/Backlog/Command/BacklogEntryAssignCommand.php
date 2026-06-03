<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPermissionService;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntryMatch;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;
use RuntimeException;

/**
 * Command for assigning an active backlog entry to a developer agent.
 */
final class BacklogEntryAssignCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    private BacklogPermissionService $permissionService;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     * @param BacklogPermissionService $permissionService
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        BacklogPermissionService $permissionService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
        $this->permissionService = $permissionService;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $actorRole = $this->permissionService->requireWorkflowRole();
        $agent = $options[BacklogCliOption::DEVELOPER->value] ?? null;
        if (!is_string($agent)) {
            throw new RuntimeException('Option --developer is required.');
        }
        if (!isset($commandArgs[0])) {
            throw new RuntimeException('assign requires <entry-ref>.');
        }
        $reference = $commandArgs[0];
        $board = $this->loadBoard();
        $actorAgent = $actorRole === BacklogPermissionService::ROLE_DEVELOPER ? $this->permissionService->requireWorkflowAgent() : null;

        $entry = $this->resolveByReference($board, $reference);
        $kind = $this->boardService->checkIsTaskEntry($entry) ? 'task' : 'feature';
        $entryRef = $this->boardService->checkIsTaskEntry($entry)
            ? $this->boardService->getTaskReviewKey($entry)
            : ($entry->getFeature() ?? '-');

        $this->permissionService->assertCanAssignEntry($actorRole, $actorAgent, $agent, $entryRef, $entry);

        $activeEntries = $this->boardService->findActiveEntriesByAgent($board, $agent);
        $conflictingEntries = array_values(array_filter(
            $activeEntries,
            static fn(BoardEntryMatch $match): bool => $match->getEntry() !== $entry,
        ));
        if ($conflictingEntries !== []) {
            throw new \RuntimeException($this->boardService->describeActiveEntryConflict($conflictingEntries, $agent));
        }

        $cleaned = $this->worktreeService->cleanupAbandonedManagedWorktrees($board);
        $entry->setDeveloper($agent);
        $this->saveBoard($board, BacklogCommandName::ASSIGN->value);

        $worktree = $this->worktreeService->prepareAgentWorktree($agent);
        $this->worktreeService->checkoutBranchInWorktree($worktree, $entry->getBranch() ?? '', false);
        $cleaned += $this->worktreeService->cleanupAbandonedManagedWorktrees($board);

        $this->presenter->displaySuccess(sprintf('Assigned %s %s to %s', $kind, $entryRef, $agent));
        if ($cleaned > 0) {
            $this->presenter->displayLine(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }
    }

    /**
     * Resolves an active feature or task from a positional reference.
     *
     * Supports stable `<entry-ref>` values, plus legacy bare task slugs. A plain slug that
     * matches both a feature and a task is rejected as ambiguous.
     *
     * @param BacklogBoard $board Loaded backlog board
     * @param string $reference User-provided entry reference
     * @return BoardEntry Resolved active backlog entry
     */
    private function resolveByReference(BacklogBoard $board, string $reference): BoardEntry
    {
        if (str_contains($reference, '/')) {
            return $this->boardService->resolveTaskByReference($board, $reference, BacklogCommandName::ASSIGN->value)->getEntry();
        }

        $slug = $this->boardService->normalizeFeatureSlug($reference);
        $featureMatch = $this->boardService->findParentFeatureEntry($board, $slug);
        $taskMatches = $this->boardService->findTaskEntriesByTaskSlug($board, $slug);

        if ($featureMatch !== null && $taskMatches !== []) {
            throw new RuntimeException(sprintf(
                'Ambiguous reference %s: matches both a feature and a task. Use a full <entry-ref> to disambiguate.',
                $reference,
            ));
        }

        if ($featureMatch !== null) {
            return $featureMatch->getEntry();
        }

        if ($taskMatches !== []) {
            if (count($taskMatches) > 1) {
                throw new RuntimeException(sprintf(
                    'assign requires a full <entry-ref> because task slug %s is not unique.',
                    $slug,
                ));
            }

            return $taskMatches[0]->getEntry();
        }

        throw new RuntimeException(sprintf('No active entry found for reference: %s', $reference));
    }
}
