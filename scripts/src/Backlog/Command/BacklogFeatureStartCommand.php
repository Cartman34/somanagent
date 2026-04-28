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
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Service\GitService;

/**
 * Command for starting a feature from a queued task.
 */
final class BacklogFeatureStartCommand extends AbstractBacklogCommand
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

    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        $branchTypeOverride = is_string($options['branch-type'] ?? null) ? $options['branch-type'] : '';

        $board = $this->loadBoard();

        if ($this->boardService->findTaskEntriesByAgent($board, $agent) !== []) {
            throw new \RuntimeException("Agent {$agent} already owns an active task.");
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
        $scopedTask = $this->boardService->extractScopedTaskMetadata($first->getText());
        if ($scopedTask !== null) {
            $task = $scopedTask['task'];
            $parent = $this->boardService->findParentFeatureEntry($board, $scopedTask['featureGroup']);

            if ($parent === null) {
                $branchType = $this->boardService->resolveFeatureStartBranchType($first, null, $branchTypeOverride);
                $featureBranch = $branchType . '/' . $scopedTask['featureGroup'];
                $branch = $branchType . '/' . $scopedTask['featureGroup'] . '--' . $task;
                $this->gitService->updateMainBranch();
                $featureBase = $this->gitService->getBranchHead(GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH);
                $this->worktreeService->ensureLocalBranchExists($featureBranch, GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH);

                $featureEntry = new BoardEntry($scopedTask['featureGroup'], []);
                $this->boardService->hydrateEntryFromMetadata($featureEntry, [
                    BoardEntry::META_KIND => BacklogBoardService::ENTRY_KIND_FEATURE,
                    BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
                    BoardEntry::META_FEATURE => $scopedTask['featureGroup'],
                    BoardEntry::META_AGENT => $agent,
                    BoardEntry::META_BRANCH => $featureBranch,
                    BoardEntry::META_BASE => $featureBase,
                    BoardEntry::META_PR => BacklogMetaValue::NONE->value,
                ]);
                $activeEntries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
                $activeEntries[] = $featureEntry;
                $board->setEntries(BacklogBoard::SECTION_ACTIVE, $activeEntries);
                $parent = $this->boardService->resolveFeature($board, $scopedTask['featureGroup']);
            } else {
                $branchType = $this->boardService->resolveFeatureStartBranchType($first, $parent->getEntry(), $branchTypeOverride);
                $featureBranch = $parent->getEntry()->getBranch() ?: ($branchType . '/' . $scopedTask['featureGroup']);
                $branch = $branchType . '/' . $scopedTask['featureGroup'] . '--' . $task;
                $this->boardService->invalidateFeatureReviewState($parent->getEntry());
            }
            $this->boardService->assertTaskSlugAvailableForFeature($board, $parent->getEntry(), $scopedTask['featureGroup'], $task, BacklogCommandName::FEATURE_START->value);

            $taskBase = $this->gitService->getBranchHead($featureBranch);
            $this->worktreeService->requireLocalBranchExists($featureBranch, BacklogCommandName::FEATURE_START->value);
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
            $featureEntry = $taskEntry;
        } else {
            $branchType = $this->boardService->resolveFeatureStartBranchType($first, null, $branchTypeOverride);
            $feature = $this->boardService->normalizeFeatureSlug($first->getText());
            $this->gitService->updateMainBranch();
            $base = $this->gitService->getBranchHead(GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH);
            $branch = $branchType . '/' . $feature;
            $this->worktreeService->checkoutBranchInWorktree($worktree, $branch, true);

            $featureEntry = new BoardEntry($first->getText(), $first->getExtraLines());
            $this->boardService->hydrateEntryFromMetadata($featureEntry, [
                BoardEntry::META_KIND => BacklogBoardService::ENTRY_KIND_FEATURE,
                BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
                BoardEntry::META_FEATURE => $feature,
                BoardEntry::META_AGENT => $agent,
                BoardEntry::META_BRANCH => $branch,
                BoardEntry::META_BASE => $base,
                BoardEntry::META_PR => BacklogMetaValue::NONE->value,
            ]);
        }

        foreach (array_slice($reserved, 1) as $task) {
            $reservedEntry = $task->getEntry();
            $reservedEntry->setFeature(null);
            $reservedEntry->setAgent(null);
            $featureEntry->appendExtraLines(['  - ' . $reservedEntry->getText()]);
            foreach ($reservedEntry->getExtraLines() as $line) {
                $featureEntry->appendExtraLines(['  ' . ltrim($line)]);
            }
        }

        $this->boardService->removeReservedTasks($board, $reserved);
        $entries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
        $entries[] = $featureEntry;
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, $entries);
        
        $this->saveBoard($board, BacklogCommandName::FEATURE_START->value);

        $this->presenter->displaySuccess(sprintf(
            'Started %s %s on %s',
            $this->boardService->getEntryKind($featureEntry),
            $featureEntry->getTask() ?? $featureEntry->getFeature() ?? '-',
            $branch,
        ));
    }
}
