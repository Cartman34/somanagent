<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Service\GitService;
use SoManAgent\Script\Service\PullRequestService;

/**
 * Command for adding a task to an active feature.
 */
final class BacklogFeatureTaskAddCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    private GitService $gitService;

    private PullRequestService $pullRequestService;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        GitService $gitService,
        PullRequestService $pullRequestService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
        $this->gitService = $gitService;
        $this->pullRequestService = $pullRequestService;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        $featureText = $this->boardService->sanitizeString((string) ($options[BacklogCliOption::FEATURE_TEXT->value] ?? ''));
        if ($featureText === null) {
            throw new \RuntimeException('feature-task-add requires --feature-text.');
        }

        $board = $this->loadBoard();
        $current = $this->boardService->resolveSingleFeatureForAgent($board, $agent);
        $feature = $current->getEntry()->getFeature();
        $target = $this->boardService->fetchNextTodoTask($board);
        if ($target === null) {
            throw new \RuntimeException('No queued task available to add to the current feature.');
        }
        $reserved = [$target];

        $entry = $current->getEntry();
        $this->boardService->checkIsFeatureEntry($entry) || throw new \RuntimeException('feature-task-add only applies to kind=feature entries.');
        $entry->setText($featureText);
        $this->invalidateFeatureReviewState($entry);

        foreach ($reserved as $task) {
            $reservedEntry = $task->getEntry();
            $reservedEntry->setFeature(null);
            $reservedEntry->setAgent(null);
            $scopedTask = $this->boardService->extractScopedTaskMetadata($reservedEntry->getText());

            if ($scopedTask !== null) {
                if ($scopedTask['featureGroup'] !== $feature) {
                    throw new \RuntimeException(sprintf(
                        'Next queued task belongs to feature %s, not %s.',
                        $scopedTask['featureGroup'],
                        $feature,
                    ));
                }
                if ($this->boardService->findTaskEntriesByAgent($board, $agent) !== []) {
                    throw new \RuntimeException(sprintf(
                        'Agent %s already owns an active task. Merge or release it before feature-task-add.',
                        $agent,
                    ));
                }

                $featureBranch = $entry->getBranch();
                $branchType = $this->detectBranchType($featureBranch);
                if ($featureBranch === null || $branchType === '') {
                    throw new \RuntimeException('Current feature metadata is incomplete: missing branch information.');
                }
                $this->boardService->assertTaskSlugAvailableForFeature($board, $entry, (string) $feature, $scopedTask['task'], BacklogCommandName::FEATURE_TASK_ADD->value);

                $taskBranch = $branchType . '/' . $feature . '--' . $scopedTask['task'];
                $taskBase = $this->gitService->getBranchHead($featureBranch);

                $worktree = $this->worktreeService->prepareAgentWorktree($agent);
                $this->worktreeService->checkoutBranchInWorktree($worktree, $taskBranch, true, $featureBranch);

                $metadata = [
                    BoardEntry::META_KIND => BacklogBoardService::ENTRY_KIND_TASK,
                    BoardEntry::META_STAGE => BacklogBoard::STAGE_IN_PROGRESS,
                    BoardEntry::META_FEATURE => $feature,
                    BoardEntry::META_TASK => $scopedTask['task'],
                    BoardEntry::META_AGENT => $agent,
                    BoardEntry::META_BRANCH => $taskBranch,
                    BoardEntry::META_FEATURE_BRANCH => $featureBranch,
                    BoardEntry::META_BASE => $taskBase,
                    BoardEntry::META_PR => BacklogMetaValue::NONE->value,
                ];
                $taskEntry = new BoardEntry($scopedTask['text'], $reservedEntry->getExtraLines());
                $this->boardService->hydrateEntryFromMetadata($taskEntry, $metadata);
                $this->boardService->appendTaskContribution($entry, $taskEntry);

                $entries = $board->getEntries(BacklogBoard::SECTION_ACTIVE);
                $entries[] = $taskEntry;
                $board->setEntries(BacklogBoard::SECTION_ACTIVE, $entries);

                continue;
            }

            if ($this->boardService->findTaskEntriesByFeature($board, (string) $feature) !== []) {
                throw new \RuntimeException(sprintf(
                    'Current feature %s already uses local child tasks. The next queued task must use [%s][task] to be attached safely.',
                    $feature,
                    $feature,
                ));
            }

            $entry->appendExtraLines(['  - ' . $reservedEntry->getText()]);
            foreach ($reservedEntry->getExtraLines() as $line) {
                $entry->appendExtraLines(['  ' . ltrim($line)]);
            }
        }

        $this->boardService->removeReservedTasks($board, $reserved);

        $this->saveBoard($board, BacklogCommandName::FEATURE_TASK_ADD->value);
        $bodyFile = isset($options[BacklogCliOption::BODY_FILE->value])
            ? (string) $options[BacklogCliOption::BODY_FILE->value]
            : null;
        if ($bodyFile !== null) {
            $this->pullRequestService->updatePrBodyIfExists($entry->getBranch() ?? '', $bodyFile);
        }

        $this->presenter->displaySuccess(sprintf('Added queued task to feature %s', $feature));
    }

    private function detectBranchType(?string $branch): string
    {
        if ($branch === null) {
            return '';
        }
        if (preg_match('/^(feat|fix)\//', $branch, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    private function invalidateFeatureReviewState(BoardEntry $featureEntry): void
    {
        if ($this->boardService->getFeatureStage($featureEntry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            $featureEntry->setStage(BacklogBoard::STAGE_IN_PROGRESS);
        }
    }
}
