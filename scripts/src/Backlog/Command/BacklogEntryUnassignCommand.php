<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use RuntimeException;
use SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPermissionService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;

/**
 * Command for unassigning a backlog entry (feature or task) from an agent.
 */
final class BacklogEntryUnassignCommand extends AbstractBacklogCommand
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
        $callerAgent = $options[BacklogCliOption::AGENT->value] ?? null;
        if (!is_string($callerAgent)) {
            throw new RuntimeException('Option --agent is required.');
        }
        $board = $this->loadBoard();

        $reference = $commandArgs[0] ?? null;
        $entry = $reference !== null
            ? $this->resolveByReference($board, $reference)
            : $this->resolveSingleActiveEntryForAgent($board, $callerAgent);

        $isTask = $this->boardService->checkIsTaskEntry($entry);
        $kind = $isTask ? 'task' : 'feature';
        $entryRef = $isTask
            ? $this->boardService->getTaskReviewKey($entry)
            : ($entry->getFeature() ?? '-');

        $actorAgent = $actorRole === BacklogPermissionService::ROLE_DEVELOPER ? $this->permissionService->requireWorkflowAgent() : null;

        $this->permissionService->assertCanUnassignEntry($actorRole, $actorAgent, $callerAgent, $entryRef, $entry);
        $assignedAgent = $entry->getDeveloper();
        if ($assignedAgent === null) {
            throw new RuntimeException(sprintf('%s %s is not assigned to any agent.', ucfirst($kind), $entryRef));
        }

        $entry->setDeveloper(null);
        $this->saveBoard($board, BacklogCommandName::ENTRY_UNASSIGN->value);
        $cleaned = $this->worktreeService->cleanupAbandonedManagedWorktrees($board);

        $this->presenter->displaySuccess(sprintf('Unassigned %s %s from %s', $kind, $entryRef, $assignedAgent));
        if ($cleaned > 0) {
            $this->presenter->displayLine(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }
    }

    /**
     * Resolves an active entry from a positional reference.
     *
     * Supports stable `<entry-ref>` values, plus legacy bare task slugs. A plain slug that
     * matches both a feature and a task is rejected as ambiguous.
     *
     * @param BacklogBoard $board
     * @param string $reference
     * @return BoardEntry
     */
    private function resolveByReference(BacklogBoard $board, string $reference): BoardEntry
    {
        if (str_contains($reference, '/')) {
            return $this->boardService->resolveTaskByReference($board, $reference, BacklogCommandName::ENTRY_UNASSIGN->value)->getEntry();
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
                    'entry-unassign requires a full <entry-ref> because task slug %s is not unique.',
                    $slug,
                ));
            }

            return $taskMatches[0]->getEntry();
        }

        throw new RuntimeException(sprintf('No active entry found for reference: %s', $reference));
    }

    /**
     * Resolves the agent's single active entry, throwing on none or multiple.
     *
     * @param BacklogBoard $board
     * @param string $agent
     * @return BoardEntry
     */
    private function resolveSingleActiveEntryForAgent(BacklogBoard $board, string $agent): BoardEntry
    {
        $matches = $this->boardService->findActiveEntriesByAgent($board, $agent);
        if ($matches === []) {
            throw new RuntimeException(sprintf('Agent %s has no active entry.', $agent));
        }
        if (count($matches) > 1) {
            throw new RuntimeException(sprintf(
                'Agent %s has multiple active entries. Provide an explicit reference.',
                $agent,
            ));
        }

        return $matches[0]->getEntry();
    }
}
