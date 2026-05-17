<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Model\BacklogReviewFile;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;

/**
 * Base class for all backlog commands.
 */
abstract class AbstractBacklogCommand
{
    protected BacklogPresenter $presenter;

    protected BacklogBoardService $boardService;

    protected bool $dryRun;

    protected string $projectRoot;

    protected ?string $boardPath = null;

    protected ?string $reviewFilePath = null;

    /**
     * AbstractBacklogCommand constructor.
     */
    public function __construct(BacklogPresenter $presenter, bool $dryRun, string $projectRoot, BacklogBoardService $boardService)
    {
        $this->presenter = $presenter;
        $this->dryRun = $dryRun;
        $this->projectRoot = $projectRoot;
        $this->boardService = $boardService;
    }

    /**
     * Sets the backlog board path.
     */
    public function setBoardPath(string $boardPath): void
    {
        $this->boardPath = $boardPath;
    }

    /**
     * Sets the review file path.
     */
    public function setReviewFilePath(string $reviewFilePath): void
    {
        $this->reviewFilePath = $reviewFilePath;
    }

    /**
     * Executes the command logic.
     *
     * @param array<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     * @throws \Exception
     */
    abstract public function handle(array $commandArgs, array $options): void;

    protected function loadBoard(?string $boardFile = null): BacklogBoard
    {
        return $this->boardService->loadBoard($boardFile ?? $this->boardPath ?? ($this->projectRoot . '/local/backlog-board.yaml'));
    }

    protected function saveBoard(BacklogBoard $board, string $reason): void
    {
        if ($this->dryRun) {
            $this->presenter->displayLine(sprintf('[dry-run] Would save board: %s', $reason));

            return;
        }

        $this->boardService->saveBoard($board);
    }

    protected function loadReviewFile(?string $reviewFile = null): BacklogReviewFile
    {
        return $this->boardService->loadReviewFile($reviewFile ?? $this->reviewFilePath ?? ($this->projectRoot . '/local/backlog-review.md'));
    }

    protected function saveReviewFile(BacklogReviewFile $review, string $reason): void
    {
        if ($this->dryRun) {
            $this->presenter->displayLine(sprintf('[dry-run] Would save review file: %s', $reason));

            return;
        }

        $this->boardService->saveReviewFile($review);
    }

    /**
     * Reads and validates the caller identity from the SOMANAGER_AGENT environment variable.
     *
     * @return string The agent code
     */
    protected function requireCallerAgent(): string
    {
        $agent = trim((string) getenv('SOMANAGER_AGENT'));
        if ($agent === '') {
            throw new \RuntimeException('Command requires SOMANAGER_AGENT=<code>.');
        }

        return $agent;
    }

    /**
     * Reads the active caller role from the SOMANAGER_ROLE environment variable without throwing.
     *
     * @return string|null 'manager', 'developer', or null when not set or invalid
     */
    protected function readCallerRole(): ?string
    {
        $role = strtolower(trim((string) getenv('SOMANAGER_ROLE')));

        return in_array($role, ['manager', 'developer'], true) ? $role : null;
    }
}
