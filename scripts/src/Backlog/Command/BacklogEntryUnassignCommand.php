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
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;
use Sowapps\SoManAgent\Script\Backlog\Command\AbstractBacklogCommand;
use RuntimeException;

/**
 * Command for unassigning a backlog entry (feature or task) from a developer.
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
        $callerDeveloper = $options[BacklogCliOption::DEVELOPER->value] ?? null;
        if (!is_string($callerDeveloper)) {
            throw new RuntimeException('Option --developer is required.');
        }
        $board = $this->loadBoard();

        $reference = $commandArgs[0] ?? null;
        $entry = $reference !== null
            ? $this->resolveByReference($board, $reference)
            : $this->resolveSingleActiveEntryForDeveloper($board, $callerDeveloper);

        $isTask = $this->boardService->checkIsTaskEntry($entry);
        $kind = $isTask ? 'task' : 'feature';
        $entryRef = $isTask
            ? $this->boardService->getTaskReviewKey($entry)
            : ($entry->getFeature() ?? '-');

        $actorDeveloper = $actorRole === BacklogPermissionService::ROLE_DEVELOPER ? $this->permissionService->requireWorkflowAgent() : null;

        $this->permissionService->assertCanUnassignEntry($actorRole, $actorDeveloper, $callerDeveloper, $entryRef, $entry);
        $assignedAgent = $entry->getDeveloper();
        if ($assignedAgent === null) {
            throw new RuntimeException(sprintf('%s %s is not assigned to any developer.', ucfirst($kind), $entryRef));
        }

        $entry->setDeveloper(null);
        $this->saveBoard($board, BacklogCommandName::UNASSIGN->value);
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
            return $this->boardService->resolveTaskByReference($board, $reference, BacklogCommandName::UNASSIGN->value)->getEntry();
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
                    'unassign requires a full <entry-ref> because task slug %s is not unique.',
                    $slug,
                ));
            }

            return $taskMatches[0]->getEntry();
        }

        throw new RuntimeException(sprintf('No active entry found for reference: %s', $reference));
    }

    /**
     * Resolves the developer's single active entry, throwing on none or multiple.
     *
     * @param BacklogBoard $board
     * @param string $developer
     * @return BoardEntry
     */
    private function resolveSingleActiveEntryForDeveloper(BacklogBoard $board, string $developer): BoardEntry
    {
        $matches = $this->boardService->findActiveEntriesByAgent($board, $developer);
        if ($matches === []) {
            throw new RuntimeException(sprintf('Developer %s has no active entry.', $developer));
        }
        if (count($matches) > 1) {
            throw new RuntimeException(sprintf(
                'Developer %s has multiple active entries. Provide an explicit reference.',
                $developer,
            ));
        }

        return $matches[0]->getEntry();
    }
}
