<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogMetaValue;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
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

        $this->presenter->displayLine('[Task]');
        if ($taskEntry !== null) {
            $this->presenter->displayEntryStatus($taskEntry->getEntry());
        } else {
            $this->presenter->displayLine('Active: ' . BacklogMetaValue::NONE->value);
        }

        $this->presenter->displayLine('');
        $this->presenter->displayLine('[Feature]');
        if ($featureEntry !== null) {
            $this->presenter->displayEntryStatus($featureEntry->getEntry());
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

        $this->presenter->displayLine('');
        $this->statusWorktree($board, $entry->getAgent());
    }

    private function statusWorktree(BacklogBoard $board, ?string $agent): void
    {
        $this->presenter->displayLine('[Worktree]');
        if ($agent === null) {
            $this->presenter->displayLine('State: unknown');
            $this->presenter->displayLine('Path: -');

            return;
        }

        $expectedPath = $this->projectRoot . '/.worktrees/' . $agent;
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
