<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Model\ManagedWorktree;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Service\GitService;

/**
 * Unified command for starting work on the next queued backlog task.
 *
 * Replaces feature-start and feature-task-add. Behaviour depends on the queued task prefix:
 *   [feature][task] text  → child kind=task under an existing or new kind=feature container (agent=none)
 *   [feature] text        → plain kind=feature with an explicit slug, assigned to the agent
 *   text (no prefix)      → plain kind=feature with a slug derived from the task text, assigned to the agent
 */
final class BacklogWorkStartCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    private GitService $gitService;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        GitService $gitService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
        $this->gitService = $gitService;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     * @return void
     */
    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        $branchTypeOverride = is_string($options['branch-type'] ?? null) ? $options['branch-type'] : '';

        $board = $this->loadBoard();

        $activeEntries = $this->boardService->findActiveEntriesByAgent($board, $agent);
        if ($activeEntries !== []) {
            throw new \RuntimeException($this->boardService->describeActiveEntryConflict($activeEntries, $agent));
        }

        $target = $this->boardService->fetchNextTodoTask($board);
        if ($target === null) {
            throw new \RuntimeException('No backlog task available to start.');
        }
        $reserved = [$target];

        $worktree = $this->worktreeService->prepareAgentWorktree($agent);
        $first = $reserved[0]->getEntry();
        $first->setFeature(null);
        $first->setAgent(null);
        $startedTaskEntry = null;
        $startedFeatureEntry = null;

        $scopedTask = $this->boardService->extractScopedTaskMetadata($first->getText());
        if ($scopedTask !== null) {
            [$featureEntry, $startedTaskEntry, $startedFeatureEntry] = $this->handleScopedTask(
                $board, $worktree, $first, $scopedTask, $agent, $branchTypeOverride
            );
        } else {
            $singleFeature = $this->boardService->extractSingleFeaturePrefixMetadata($first->getText());
            [$featureEntry, $startedFeatureEntry] = $this->handlePlainFeature(
                $worktree, $first, $agent, $branchTypeOverride, $singleFeature
            );
        }

        $this->boardService->removeReservedTasks($board, $reserved);
        $entries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
        $entries[] = $featureEntry;
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, $entries);

        $this->saveBoard($board, BacklogCommandName::WORK_START->value);

        $this->presenter->displaySuccess(sprintf(
            'Started %s %s on %s',
            $this->boardService->getEntryKind($featureEntry),
            $featureEntry->getTask() ?? $featureEntry->getFeature() ?? '-',
            $featureEntry->getBranch() ?? '-',
        ));
        $this->displayStartedStatus($board, $worktree, $startedTaskEntry, $startedFeatureEntry);
    }

    /**
     * @param array{featureGroup: string, task: string, text: string} $scopedTask
     * @return array{BoardEntry, BoardEntry, BoardEntry}
     */
    private function handleScopedTask(
        BacklogBoard $board,
        string $worktree,
        BoardEntry $first,
        array $scopedTask,
        string $agent,
        string $branchTypeOverride
    ): array {
        $task = $scopedTask['task'];
        $parent = $this->boardService->findParentFeatureEntry($board, $scopedTask['featureGroup']);

        if ($parent === null) {
            $branchType = $this->boardService->resolveFeatureStartBranchType($first, null, $branchTypeOverride);
            $featureBranch = $branchType . '/' . $scopedTask['featureGroup'];
            $branch = $branchType . '/' . $scopedTask['featureGroup'] . '--' . $task;
            $this->gitService->updateMainBranch();
            $featureBase = $this->gitService->getBranchHead(GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH);
            $this->worktreeService->ensureLocalBranchExists($featureBranch, GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH);

            $featureContainerEntry = new BoardEntry($scopedTask['featureGroup'], []);
            $this->boardService->hydrateEntryFromMetadata($featureContainerEntry, [
                BoardEntry::META_KIND => BacklogBoardService::ENTRY_KIND_FEATURE,
                BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
                BoardEntry::META_FEATURE => $scopedTask['featureGroup'],
                BoardEntry::META_AGENT => BacklogMetaValue::NONE->value,
                BoardEntry::META_BRANCH => $featureBranch,
                BoardEntry::META_BASE => $featureBase,
                BoardEntry::META_PR => BacklogMetaValue::NONE->value,
            ]);
            $activeEntries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
            $activeEntries[] = $featureContainerEntry;
            $board->setEntries(BacklogBoard::SECTION_ACTIVE, $activeEntries);
            $parent = $this->boardService->resolveFeature($board, $scopedTask['featureGroup']);
        } else {
            $branchType = $this->boardService->resolveFeatureStartBranchType($first, $parent->getEntry(), $branchTypeOverride);
            $featureBranch = $parent->getEntry()->getBranch() ?: ($branchType . '/' . $scopedTask['featureGroup']);
            $branch = $branchType . '/' . $scopedTask['featureGroup'] . '--' . $task;
            $this->boardService->invalidateFeatureReviewState($parent->getEntry());
        }
        $this->boardService->assertTaskSlugAvailableForFeature($board, $parent->getEntry(), $scopedTask['featureGroup'], $task, BacklogCommandName::WORK_START->value);

        $taskBase = $this->gitService->getBranchHead($featureBranch);
        $this->worktreeService->requireLocalBranchExists($featureBranch, BacklogCommandName::WORK_START->value);
        $this->worktreeService->checkoutBranchInWorktree($worktree, $branch, true, $featureBranch);

        $taskEntry = new BoardEntry($scopedTask['text'], $first->getExtraLines());
        $this->boardService->hydrateEntryFromMetadata($taskEntry, [
            BoardEntry::META_KIND => BacklogBoardService::ENTRY_KIND_TASK,
            BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
            BoardEntry::META_FEATURE => $scopedTask['featureGroup'],
            BoardEntry::META_TASK => $task,
            BoardEntry::META_AGENT => $agent,
            BoardEntry::META_BRANCH => $branch,
            BoardEntry::META_FEATURE_BRANCH => $featureBranch,
            BoardEntry::META_BASE => $taskBase,
            BoardEntry::META_PR => BacklogMetaValue::NONE->value,
        ]);
        $this->boardService->appendTaskContribution($parent->getEntry(), $taskEntry);

        return [$taskEntry, $taskEntry, $parent->getEntry()];
    }

    /**
     * @param array{featureSlug: string, text: string}|null $singleFeature
     * @return array{BoardEntry, BoardEntry}
     */
    private function handlePlainFeature(
        string $worktree,
        BoardEntry $first,
        string $agent,
        string $branchTypeOverride,
        ?array $singleFeature
    ): array {
        $branchType = $this->boardService->resolveFeatureStartBranchType($first, null, $branchTypeOverride);
        $this->gitService->updateMainBranch();
        $base = $this->gitService->getBranchHead(GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH);

        if ($singleFeature !== null) {
            $feature = $singleFeature['featureSlug'];
            $entryText = $singleFeature['text'];
        } else {
            $feature = $this->boardService->normalizeFeatureSlug($first->getText());
            $entryText = $first->getText();
        }

        $branch = $branchType . '/' . $feature;
        $this->worktreeService->checkoutBranchInWorktree($worktree, $branch, true);

        $featureEntry = new BoardEntry($entryText, $first->getExtraLines());
        $this->boardService->hydrateEntryFromMetadata($featureEntry, [
            BoardEntry::META_KIND => BacklogBoardService::ENTRY_KIND_FEATURE,
            BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
            BoardEntry::META_FEATURE => $feature,
            BoardEntry::META_AGENT => $agent,
            BoardEntry::META_BRANCH => $branch,
            BoardEntry::META_BASE => $base,
            BoardEntry::META_PR => BacklogMetaValue::NONE->value,
        ]);

        return [$featureEntry, $featureEntry];
    }

    private function displayStartedStatus(
        BacklogBoard $board,
        string $worktreePath,
        ?BoardEntry $taskEntry,
        ?BoardEntry $featureEntry
    ): void {
        $this->presenter->displayLine('');
        $this->presenter->displayLine('[Task]');
        if ($taskEntry !== null) {
            $this->presenter->displayEntryStatus($taskEntry);
        } else {
            $this->presenter->displayLine('Active: ' . BacklogMetaValue::NONE->value);
        }

        $this->presenter->displayLine('');
        $this->presenter->displayLine('[Feature]');
        if ($featureEntry !== null) {
            $this->presenter->displayEntryStatus($featureEntry);
        } else {
            $this->presenter->displayLine('Active: ' . BacklogMetaValue::NONE->value);
        }

        $this->presenter->displayLine('');
        $this->presenter->displayLine('[Worktree]');
        $worktree = $this->findManagedWorktree($board, $worktreePath);
        if ($worktree === null) {
            $this->presenter->displayLine('State: absent');
            $this->presenter->displayLine('Path: ' . $worktreePath);

            return;
        }

        $this->presenter->displayManagedWorktreeStatus($worktree);
    }

    private function findManagedWorktree(BacklogBoard $board, string $worktreePath): ?ManagedWorktree
    {
        foreach ($this->worktreeService->classifyWorktrees($board)->getManaged() as $worktree) {
            if ($worktree->getPath() === $worktreePath) {
                return $worktree;
            }
        }

        return null;
    }
}
