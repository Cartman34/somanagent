<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogGitWorkflow;
use SoManAgent\Script\Backlog\BacklogMetaValue;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\Backlog\BacklogPresenter;

/**
 * Command for starting a feature from a queued task.
 */
final class BacklogFeatureStartCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogWorktreeManager $worktreeManager;

    private BacklogGitWorkflow $gitWorkflow;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogWorktreeManager $worktreeManager,
        BacklogGitWorkflow $gitWorkflow
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
        $this->worktreeManager = $worktreeManager;
        $this->gitWorkflow = $gitWorkflow;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        $branchTypeOverride = is_string($options['branch-type'] ?? null) ? $options['branch-type'] : '';

        $board = $this->loadBoard();

        if ($this->entryResolver->getSingleTaskForAgent($board, $agent, false) !== null) {
            throw new \RuntimeException("Agent {$agent} already owns an active task.");
        }

        $target = $this->entryService->nextTodoTask($board);
        if ($target === null) {
            throw new \RuntimeException('No backlog task available to start.');
        }
        $reserved = [$target];

        $worktree = $this->worktreeManager->prepareAgentWorktree($agent);
        $first = $reserved[0]->getEntry();
        $first->setFeature(null);
        $first->setAgent(null);
        $scopedTask = $this->entryService->extractScopedTaskMetadata($first->getText());
        if ($scopedTask !== null) {
            $task = $scopedTask['task'];
            $parent = $this->entryResolver->findParentFeatureEntry($board, $scopedTask['featureGroup']);

            if ($parent === null) {
                $branchType = $this->entryService->resolveFeatureStartBranchType($first, null, $branchTypeOverride);
                $featureBranch = $branchType . '/' . $scopedTask['featureGroup'];
                $branch = $branchType . '/' . $scopedTask['featureGroup'] . '--' . $task;
                $this->gitWorkflow->updateMainBeforeFeatureStart();
                $featureBase = $this->gitWorkflow->originMainHead();
                $this->worktreeManager->ensureLocalBranchExists($featureBranch, BacklogGitWorkflow::ORIGIN_REMOTE . '/' . BacklogGitWorkflow::MAIN_BRANCH);

                $featureEntry = new BoardEntry($scopedTask['featureGroup'], [], [
                    BoardEntry::META_KIND => BacklogEntryService::ENTRY_KIND_FEATURE,
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
                $parent = $this->entryResolver->requireParentFeature($board, $scopedTask['featureGroup']);
            } else {
                $branchType = $this->entryService->resolveFeatureStartBranchType($first, $parent->getEntry(), $branchTypeOverride);
                $featureBranch = $parent->getEntry()->getBranch() ?: ($branchType . '/' . $scopedTask['featureGroup']);
                $branch = $branchType . '/' . $scopedTask['featureGroup'] . '--' . $task;
                $this->entryService->invalidateFeatureReviewState($parent->getEntry());
            }
            $this->entryService->assertTaskSlugAvailableForFeature($board, $parent->getEntry(), $scopedTask['featureGroup'], $task, BacklogCommandName::FEATURE_START->value);

            $taskBase = $this->gitWorkflow->branchHead($featureBranch);
            $this->worktreeManager->requireLocalBranchExists($featureBranch, BacklogCommandName::FEATURE_START->value);
            $this->worktreeManager->checkoutBranchInWorktree($worktree, $branch, true, $featureBranch);

            $taskEntry = new BoardEntry($scopedTask['text'], $first->getExtraLines(), [
                BoardEntry::META_KIND => BacklogEntryService::ENTRY_KIND_TASK,
                BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
                BoardEntry::META_FEATURE => $scopedTask['featureGroup'],
                BoardEntry::META_TASK => $task,
                BoardEntry::META_AGENT => $agent,
                BoardEntry::META_BRANCH => $branch,
                BoardEntry::META_FEATURE_BRANCH => $featureBranch,
                BoardEntry::META_BASE => $taskBase,
                BoardEntry::META_PR => BacklogMetaValue::NONE->value,
            ]);
            $this->entryService->appendTaskContribution($parent->getEntry(), $taskEntry);
            $featureEntry = $taskEntry;
        } else {
            $branchType = $this->entryService->resolveFeatureStartBranchType($first, null, $branchTypeOverride);
            $feature = $this->entryService->normalizeFeatureSlug($first->getText());
            $this->gitWorkflow->updateMainBeforeFeatureStart();
            $base = $this->gitWorkflow->originMainHead();
            $branch = $branchType . '/' . $feature;
            $this->worktreeManager->checkoutBranchInWorktree($worktree, $branch, true);

            $featureEntry = new BoardEntry($first->getText(), $first->getExtraLines(), [
                BoardEntry::META_KIND => BacklogEntryService::ENTRY_KIND_FEATURE,
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

        $this->entryService->removeReservedTasks($board, $reserved);
        $entries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
        $entries[] = $featureEntry;
        $board->setEntries(BacklogBoard::SECTION_ACTIVE, $entries);
        
        $this->saveBoard($board, BacklogCommandName::FEATURE_START->value);

        $this->presenter->displaySuccess(sprintf(
            'Started %s %s on %s',
            $this->entryService->entryKind($featureEntry),
            $featureEntry->getTask() ?? $featureEntry->getFeature() ?? '-',
            $branch,
        ));
    }
}
