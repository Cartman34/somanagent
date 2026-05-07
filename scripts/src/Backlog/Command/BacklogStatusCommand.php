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

/**
 * Command for displaying the backlog status.
 */
final class BacklogStatusCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $agent = $options['agent'] ?? null;
        if (!is_string($agent) && $agent !== null) {
            throw new \RuntimeException('Option --agent must be a string.');
        }

        if ($agent !== null) {
            $this->statusForAgent($board, $agent);

            return;
        }

        $requestedTarget = $commandArgs[0] ?? null;
        if ($requestedTarget !== null) {
            $this->statusForFeature($board, $requestedTarget);

            return;
        }

        $this->statusGeneral($board);
    }

    private function statusGeneral(BacklogBoard $board): void
    {
        foreach ($this->boardService->getActiveStages() as $stage) {
            $this->presenter->displayStageHeader($stage);
            $matches = $this->boardService->fetchFeaturesByStage($board, $stage);
            if ($matches === []) {
                $this->presenter->displayLine('  none');
            } else {
                foreach ($matches as $match) {
                    $this->presenter->displayEntryLine($match->getEntry());
                }
            }
            $this->presenter->displayLine('');
        }

        $this->presenter->displayLine('[' . BacklogBoard::SECTION_TODO . ']');
        $reserved = $this->boardService->fetchReservedTasks($board);
        if ($reserved === []) {
            $this->presenter->displayLine('  none');
        } else {
            foreach ($reserved as $match) {
                $this->presenter->displayTodoEntryLine($match->getEntry());
            }
        }
    }

    private function statusForAgent(BacklogBoard $board, string $agent): void
    {
        $taskEntry = $this->boardService->findTaskEntriesByAgent($board, $agent)[0] ?? null;
        $featureEntry = $this->boardService->findFeatureEntriesByAgent($board, $agent)[0] ?? null;
        $reviewKeys = $this->loadReviewKeys();

        $this->presenter->displayLine('[Task]');
        if ($taskEntry !== null) {
            $this->presenter->displayEntryStatus($taskEntry->getEntry());
            $this->displayReviewNotesHint($taskEntry->getEntry(), $reviewKeys);
        } else {
            $this->presenter->displayLine('Active: ' . BacklogMetaValue::NONE->value);
        }

        $this->presenter->displayLine('');
        $this->presenter->displayLine('[Feature]');
        if ($featureEntry !== null) {
            $this->presenter->displayEntryStatus($featureEntry->getEntry());
            $this->displayReviewNotesHint($featureEntry->getEntry(), $reviewKeys);
        } else {
            $this->presenter->displayLine('Active: ' . BacklogMetaValue::NONE->value);
        }

        $this->presenter->displayLine('');
        $this->statusWorktree($board, $agent);
    }

    private function statusForFeature(BacklogBoard $board, string $requestedTarget): void
    {
        $target = $this->boardService->normalizeFeatureSlug($requestedTarget);
        $match = $this->boardService->resolveFeature($board, $target);
        $entry = $match->getEntry();

        $this->presenter->displayLine('[Feature]');
        $this->presenter->displayEntryStatus($entry);
        $this->displayReviewNotesHint($entry, $this->loadReviewKeys());

        $this->presenter->displayLine('');
        $this->statusWorktree($board, $entry->getAgent());
    }

    /**
     * @return array<string, true>
     */
    private function loadReviewKeys(): array
    {
        $keys = [];
        foreach ($this->loadReviewFile()->getReviews() as $key => $items) {
            if ($items !== []) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, true> $reviewKeys
     */
    private function displayReviewNotesHint(BoardEntry $entry, array $reviewKeys): void
    {
        $reviewKey = $this->boardService->checkIsTaskEntry($entry)
            ? $this->boardService->getTaskReviewKey($entry)
            : ($entry->getFeature() ?? '-');

        if (!isset($reviewKeys[$reviewKey])) {
            return;
        }

        $this->presenter->displayLine(sprintf(
            'Review notes: stored — read with `php scripts/backlog.php %s %s` (notes hidden here).',
            BacklogCommandName::REVIEW_NOTES->value,
            $reviewKey,
        ));
    }

    private function statusWorktree(BacklogBoard $board, ?string $agent): void
    {
        $this->presenter->displayLine('[Worktree]');
        if ($agent === null) {
            $this->presenter->displayLine('State: unknown');
            $this->presenter->displayLine('Path: -');

            return;
        }

        $expectedPath = $this->worktreeService->getAgentWorktreePath($agent);
        $worktree = null;
        foreach ($this->worktreeService->classifyWorktrees($board)->getManaged() as $item) {
            if ($item->getPath() === $expectedPath) {
                $worktree = $item;
                break;
            }
        }

        if ($worktree === null) {
            $this->presenter->displayLine('State: absent');
            $this->presenter->displayLine('Path: ' . $expectedPath);

            return;
        }

        $this->presenter->displayManagedWorktreeStatus($worktree);
    }
}
