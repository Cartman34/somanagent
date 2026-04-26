<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogReviewFile;
use SoManAgent\Script\Console;

/**
 * Base class for all backlog commands.
 */
abstract class AbstractBacklogCommand
{
    public const ROLE_MANAGER = 'manager';
    public const ROLE_DEVELOPER = 'developer';

    protected Console $console;

    protected bool $dryRun;

    protected string $projectRoot;

    protected ?string $boardPath = null;

    protected ?string $reviewFilePath = null;

    protected BacklogCommandContext $context;

    public function __construct(BacklogCommandContext $context)
    {
        $this->context = $context;
        $this->console = $context->getConsole();
        $this->dryRun = $context->isDryRun();
        $this->projectRoot = $context->getProjectRoot();
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
            $this->console->line(sprintf('[dry-run] Would save board: %s', $reason));

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
            $this->console->line(sprintf('[dry-run] Would save review file: %s', $reason));

            return;
        }

        $review->save();
    }
}
