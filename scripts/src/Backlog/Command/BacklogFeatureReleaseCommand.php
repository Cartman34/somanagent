<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Service\GitService;

/**
 * Command for releasing an active feature or task back to the todo section.
 */
final class BacklogFeatureReleaseCommand extends AbstractBacklogCommand
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
        $board = $this->loadBoard();
        $requestedTarget = $this->boardService->sanitizeString($commandArgs[0] ?? null);
        if ($requestedTarget !== null) {
            $target = $this->boardService->normalizeFeatureSlug($requestedTarget);
            $task = $this->boardService->findTaskEntriesByAgent($board, $agent)[0] ?? null;
            if ($task !== null) {
                if ($task->getEntry()->getTask() === $target) {
                    $current = $this->boardService->resolveSingleTaskForAgent($board, $agent);
                } else {
                    $current = $this->boardService->resolveSingleFeatureForAgent($board, $agent);
                    if ($current->getEntry()->getFeature() !== $target) {
                        throw new \RuntimeException(sprintf(
                            'Agent %s has no active feature or task matching %s.',
                            $agent,
                            $target,
                        ));
                    }
                }
            } else {
                $current = $this->boardService->resolveSingleFeatureForAgent($board, $agent);
                if ($current->getEntry()->getFeature() !== $target) {
                    throw new \RuntimeException(sprintf(
                        'Agent %s has no active feature or task matching %s.',
                        $agent,
                        $target,
                    ));
                }
            }
        } else {
            $current = $this->boardService->findTaskEntriesByAgent($board, $agent)[0] ?? $this->boardService->resolveSingleFeatureForAgent($board, $agent);
        }
        $entry = $current->getEntry();
        $branch = $entry->getBranch();
        if ($branch === null) {
            throw new \RuntimeException('Active entry has no branch metadata.');
        }

        if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            throw new \RuntimeException('Active entry must be in ' . $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_PROGRESS) . ' to be released.');
        }
        if (!$this->featureHasNoDevelopment($entry)) {
            throw new \RuntimeException('Active entry already has development work and cannot be released back to todo.');
        }

        if ($this->boardService->checkIsTaskEntry($entry)) {
            $feature = $entry->getFeature() ?? '';
            $task = $entry->getTask() ?? '';
            $parent = $this->boardService->resolveFeature($board, $feature);
            $todoEntries = $board->getEntries(BacklogBoard::SECTION_TODO);
            array_unshift($todoEntries, new BoardEntry(
                sprintf('[%s][%s] %s', $feature, $task, $entry->getText()),
                $entry->getExtraLines(),
            ));
            $board->setEntries(BacklogBoard::SECTION_TODO, $todoEntries);
            $this->boardService->removeActiveEntryAt($board, $current->getIndex());
            $hasFeatureContent = $this->boardService->removeTaskContribution($parent->getEntry(), $entry);
            if (!$hasFeatureContent) {
                if (!$this->featureHasNoDevelopment($parent->getEntry())) {
                    throw new \RuntimeException("Parent feature {$feature} still has development work and cannot be removed.");
                }
            }
            if (!$hasFeatureContent) {
                $this->boardService->removeActiveEntryAt($board, $parent->getIndex());
                $parentBranch = $parent->getEntry()->getBranch();
                if ($parentBranch !== null) {
                    $this->gitService->deleteLocalBranch($parentBranch);
                }
            }
            $this->saveBoard($board, BacklogCommandName::FEATURE_RELEASE->value);
            $cleaned = $this->worktreeService->cleanupManagedWorktreesForBranch($branch, $board);
            $this->gitService->deleteLocalBranch($branch);

            $this->presenter->displaySuccess(sprintf('Released task %s back to todo', $task));
            if ($cleaned > 0) {
                $this->presenter->displayLine(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
            }

            return;
        }

        $feature = $entry->getFeature() ?? '';
        $this->boardService->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_RELEASE->value);
        $todoEntries = $board->getEntries(BacklogBoard::SECTION_TODO);
        array_unshift($todoEntries, new BoardEntry($entry->getText(), $entry->getExtraLines()));
        $board->setEntries(BacklogBoard::SECTION_TODO, $todoEntries);
        $this->boardService->deleteFeature($board, $feature);
        $this->saveBoard($board, BacklogCommandName::FEATURE_RELEASE->value);

        $cleaned = $this->worktreeService->cleanupManagedWorktreesForBranch($branch, $board);
        $this->gitService->deleteLocalBranch($branch);

        $this->presenter->displaySuccess(sprintf('Released feature %s back to todo', $feature));
        if ($cleaned > 0) {
            $this->presenter->displayLine(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }
    }

    private function featureHasNoDevelopment(BoardEntry $entry): bool
    {
        $branch = $entry->getBranch();
        $base = $entry->getBase();
        if ($branch === null) {
            throw new \RuntimeException('Feature metadata is incomplete: missing branch or base.');
        }
        if ($base === null) {
            throw new \RuntimeException('Feature metadata is incomplete: missing branch or base.');
        }

        return $this->gitService->getChangedFiles($base, $branch) === [];
    }
}
