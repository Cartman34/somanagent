<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use SoManAgent\Script\Backlog\Enum\BacklogTaskType;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Model\ManagedWorktree;
use SoManAgent\Script\Backlog\Model\WorkStartPlan;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Service\GitService;

/**
 * Unified command for starting work on the next queued backlog task.
 *
 * Behaviour depends on the queued task prefix combination (any order, type-aware):
 *   [type][feature][task] text  → child kind=task under an existing or new unassigned kind=feature container
 *   [type][feature] text        → plain kind=feature with an explicit slug, assigned to the agent
 *   [type] text                 → plain kind=feature with a slug derived from the task text, assigned to the agent
 *   text (no prefix)            → plain kind=feature with a slug derived from the task text, default type=feat
 *
 * The full queued task is parsed and validated before any worktree, branch or backlog
 * mutation happens. With `--dry-run`, the resolved plan is printed and no side effects
 * are performed beyond Git reads (fetch / `origin/main`).
 */
final class BacklogWorkStartCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    private GitService $gitService;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     * @param GitService $gitService
     */
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

        $first = $target->getEntry();
        $first->setFeature(null);
        $first->setAgent(null);

        $plan = $this->buildPlan($board, $first, $agent, $branchTypeOverride);

        if ($this->dryRun) {
            $this->displayDryRunPlan($plan);

            return;
        }

        $reserved = [$target];
        $worktree = $this->worktreeService->prepareAgentWorktree($agent);

        if ($plan->kind === WorkStartPlan::KIND_TASK) {
            [$featureEntry, $startedTaskEntry, $startedFeatureEntry] = $this->executeScopedTask(
                $board, $worktree, $first, $plan
            );
        } else {
            [$featureEntry, $startedFeatureEntry] = $this->executePlainFeature(
                $board, $worktree, $first, $plan
            );
            $startedTaskEntry = null;
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
     * Builds the read-only plan describing the queued task interpretation.
     *
     * Validates the type override and feature/task slug shape; rejects conflicts
     * (existing feature, duplicate task slug) without performing any mutation.
     */
    private function buildPlan(
        BacklogBoard $board,
        BoardEntry $first,
        string $agent,
        string $branchTypeOverride
    ): WorkStartPlan {
        $scopedTask = $this->boardService->extractScopedTaskMetadata($first->getText());
        if ($scopedTask !== null) {
            return $this->buildScopedTaskPlan($board, $first, $scopedTask, $agent, $branchTypeOverride);
        }

        $singleFeature = $this->boardService->extractSingleFeaturePrefixMetadata($first->getText());

        return $this->buildPlainFeaturePlan($board, $first, $agent, $branchTypeOverride, $singleFeature);
    }

    /**
     * @param array{featureGroup: string, task: string, text: string} $scopedTask
     */
    private function buildScopedTaskPlan(
        BacklogBoard $board,
        BoardEntry $first,
        array $scopedTask,
        string $agent,
        string $branchTypeOverride
    ): WorkStartPlan {
        $parent = $this->boardService->findParentFeatureEntry($board, $scopedTask['featureGroup']);

        $type = $this->boardService->resolveTaskTypeOrDefault(
            $first,
            $parent?->getEntry(),
            $branchTypeOverride
        );

        if ($parent === null) {
            $featureBranch = $type->branchPrefix() . '/' . $scopedTask['featureGroup'];
            $needsCreation = true;
        } else {
            $featureBranch = $parent->getEntry()->getBranch() ?: ($type->branchPrefix() . '/' . $scopedTask['featureGroup']);
            $needsCreation = false;
            foreach ($this->boardService->findTaskEntriesByFeature($board, $scopedTask['featureGroup']) as $match) {
                if ($match->getEntry()->getTask() === $scopedTask['task']) {
                    throw new \RuntimeException(sprintf(
                        '%s: Task slug %s is already used for feature %s.',
                        BacklogCommandName::WORK_START->value,
                        $scopedTask['task'],
                        $scopedTask['featureGroup'],
                    ));
                }
            }
        }
        $taskBranch = $type->branchPrefix() . '/' . $scopedTask['featureGroup'] . '--' . $scopedTask['task'];

        return new WorkStartPlan(
            kind: WorkStartPlan::KIND_TASK,
            type: $type,
            featureSlug: $scopedTask['featureGroup'],
            taskSlug: $scopedTask['task'],
            entryText: $scopedTask['text'],
            featureBranch: $featureBranch,
            taskBranch: $taskBranch,
            featureContainerNeedsCreation: $needsCreation,
            agent: $agent,
        );
    }

    /**
     * @param array{featureSlug: string, text: string}|null $singleFeature
     */
    private function buildPlainFeaturePlan(
        BacklogBoard $board,
        BoardEntry $first,
        string $agent,
        string $branchTypeOverride,
        ?array $singleFeature
    ): WorkStartPlan {
        $type = $this->boardService->resolveTaskTypeOrDefault($first, null, $branchTypeOverride);

        if ($singleFeature !== null) {
            $feature = $singleFeature['featureSlug'];
            $entryText = $singleFeature['text'];
        } else {
            $feature = $this->boardService->normalizeFeatureSlug($first->getText());
            $entryText = $first->getText();
        }

        if ($this->boardService->findParentFeatureEntry($board, $feature) !== null) {
            throw new \RuntimeException(
                "Feature {$feature} already exists in active entries.\n" .
                "Use `php scripts/backlog.php work-start --agent={$agent}` only for new features, " .
                "or prefix the task as [{$feature}][task-slug] to add a child task instead."
            );
        }

        return new WorkStartPlan(
            kind: WorkStartPlan::KIND_FEATURE,
            type: $type,
            featureSlug: $feature,
            taskSlug: null,
            entryText: $entryText,
            featureBranch: $type->branchPrefix() . '/' . $feature,
            taskBranch: null,
            featureContainerNeedsCreation: false,
            agent: $agent,
        );
    }

    /**
     * Performs the planned scoped-task mutations.
     *
     * @return array{BoardEntry, BoardEntry, BoardEntry}
     */
    private function executeScopedTask(
        BacklogBoard $board,
        string $worktree,
        BoardEntry $first,
        WorkStartPlan $plan
    ): array {
        $featureGroup = $plan->featureSlug;
        $task = (string) $plan->taskSlug;
        $featureBranch = $plan->featureBranch;
        $branch = (string) $plan->taskBranch;

        if ($plan->featureContainerNeedsCreation) {
            $this->gitService->updateMainBranch();
            $featureBase = $this->gitService->getBranchHead(GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH);
            $this->worktreeService->ensureLocalBranchExists($featureBranch, GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH);

            $featureContainerEntry = new BoardEntry($plan->entryText, []);
            $this->boardService->hydrateEntryFromMetadata($featureContainerEntry, [
                BoardEntry::META_KIND => BacklogBoardService::ENTRY_KIND_FEATURE,
                BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
                BoardEntry::META_FEATURE => $featureGroup,
                BoardEntry::META_AGENT => BacklogMetaValue::NONE->value,
                BoardEntry::META_BRANCH => $featureBranch,
                BoardEntry::META_BASE => $featureBase,
                BoardEntry::META_PR => BacklogMetaValue::NONE->value,
            ]);
            $activeEntries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
            $activeEntries[] = $featureContainerEntry;
            $board->setEntries(BacklogBoard::SECTION_ACTIVE, $activeEntries);
        }

        $parent = $this->boardService->resolveFeature($board, $featureGroup);
        if (!$plan->featureContainerNeedsCreation) {
            $this->boardService->invalidateFeatureReviewState($parent->getEntry());
        }

        $taskBase = $this->gitService->getBranchHead($featureBranch);
        $this->worktreeService->requireLocalBranchExists($featureBranch, BacklogCommandName::WORK_START->value);
        $this->worktreeService->checkoutBranchInWorktree($worktree, $branch, true, $featureBranch);

        $taskEntry = new BoardEntry($plan->entryText, $first->getExtraLines());
        $this->boardService->hydrateEntryFromMetadata($taskEntry, [
            BoardEntry::META_KIND => BacklogBoardService::ENTRY_KIND_TASK,
            BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
            BoardEntry::META_FEATURE => $featureGroup,
            BoardEntry::META_TASK => $task,
            BoardEntry::META_AGENT => $plan->agent,
            BoardEntry::META_BRANCH => $branch,
            BoardEntry::META_FEATURE_BRANCH => $featureBranch,
            BoardEntry::META_BASE => $taskBase,
            BoardEntry::META_PR => BacklogMetaValue::NONE->value,
        ]);
        $this->boardService->appendTaskContribution($parent->getEntry(), $taskEntry);

        return [$taskEntry, $taskEntry, $parent->getEntry()];
    }

    /**
     * Performs the planned plain-feature mutations.
     *
     * @return array{BoardEntry, BoardEntry}
     */
    private function executePlainFeature(
        BacklogBoard $board,
        string $worktree,
        BoardEntry $first,
        WorkStartPlan $plan
    ): array {
        $this->gitService->updateMainBranch();
        $base = $this->gitService->getBranchHead(GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH);

        $branch = $plan->featureBranch;
        $this->worktreeService->checkoutBranchInWorktree($worktree, $branch, true);

        $featureEntry = new BoardEntry($plan->entryText, $first->getExtraLines());
        $this->boardService->hydrateEntryFromMetadata($featureEntry, [
            BoardEntry::META_KIND => BacklogBoardService::ENTRY_KIND_FEATURE,
            BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
            BoardEntry::META_FEATURE => $plan->featureSlug,
            BoardEntry::META_AGENT => $plan->agent,
            BoardEntry::META_BRANCH => $branch,
            BoardEntry::META_BASE => $base,
            BoardEntry::META_PR => BacklogMetaValue::NONE->value,
        ]);

        return [$featureEntry, $featureEntry];
    }

    /**
     * Prints the resolved interpretation of the queued task without performing any mutation.
     */
    private function displayDryRunPlan(WorkStartPlan $plan): void
    {
        $this->presenter->displayLine('[Dry-run]');
        $this->presenter->displayLine('Kind:           ' . $plan->kind);
        $this->presenter->displayLine('Type:           ' . $plan->type->value);
        $this->presenter->displayLine('Feature:        ' . $plan->featureSlug);
        if ($plan->taskSlug !== null) {
            $this->presenter->displayLine('Task:           ' . $plan->taskSlug);
        }
        $this->presenter->displayLine('Entry text:     ' . $plan->entryText);
        $this->presenter->displayLine('Feature branch: ' . $plan->featureBranch);
        if ($plan->taskBranch !== null) {
            $this->presenter->displayLine('Task branch:    ' . $plan->taskBranch);
        }
        if ($plan->kind === WorkStartPlan::KIND_TASK) {
            $this->presenter->displayLine('Feature parent: ' . ($plan->featureContainerNeedsCreation ? 'will be created' : 'already exists'));
        }
        $this->presenter->displayLine('Agent:          ' . $plan->agent);
        $this->presenter->displayLine('No mutation performed (--dry-run).');
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
