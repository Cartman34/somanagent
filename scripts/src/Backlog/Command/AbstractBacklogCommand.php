<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogPresenter;
use SoManAgent\Script\Backlog\BacklogReviewFile;

/**
 * Base class for all backlog commands.
 */
abstract class AbstractBacklogCommand
{
    protected BacklogPresenter $presenter;

    protected bool $dryRun;

    protected string $projectRoot;

    protected ?string $boardPath = null;

    protected ?string $reviewFilePath = null;

    public function __construct(BacklogPresenter $presenter, bool $dryRun, string $projectRoot)
    {
        $this->presenter = $presenter;
        $this->dryRun = $dryRun;
        $this->projectRoot = $projectRoot;
    }

    public function setBoardPath(string $boardPath): void
    {
        $this->boardPath = $boardPath;
    }

    public function setReviewFilePath(string $reviewFilePath): void
    {
        $this->reviewFilePath = $reviewFilePath;
    }

    /**
     * Executes the command logic.
     *
     * @param array<string> $commandArgs
     * @param array<string, string|bool> $options
     * @throws \Exception
     */
    abstract public function handle(array $commandArgs, array $options): void;

    protected function loadBoard(?string $boardFile = null): BacklogBoard
    {
        return new BacklogBoard($boardFile ?? $this->boardPath ?? ($this->projectRoot . '/local/backlog-board.md'));
    }

    protected function saveBoard(BacklogBoard $board, string $reason): void
    {
        if ($this->dryRun) {
            $this->presenter->displayLine(sprintf('[dry-run] Would save board: %s', $reason));

            return;
        }

        $board->save();
    }

    protected function loadReviewFile(?string $reviewFile = null): BacklogReviewFile
    {
        return new BacklogReviewFile($reviewFile ?? $this->reviewFilePath ?? ($this->projectRoot . '/local/backlog-review.md'));
    }

    protected function saveReviewFile(BacklogReviewFile $review, string $reason): void
    {
        if ($this->dryRun) {
            $this->presenter->displayLine(sprintf('[dry-run] Would save review file: %s', $reason));

            return;
        }

        $review->save();
    }
}
