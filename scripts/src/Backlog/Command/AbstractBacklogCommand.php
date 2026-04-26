<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogReviewFile;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\Console;

/**
 * Base class for all backlog commands.
 */
abstract class AbstractBacklogCommand
{
    public const ROLE_MANAGER = 'manager';
    public const ROLE_DEVELOPER = 'developer';

    protected const ENV_ACTIVE_ROLE = 'SOMANAGER_ROLE';
    protected const ENV_ACTIVE_AGENT = 'SOMANAGER_AGENT';

    protected Console $console;

    protected bool $dryRun;

    protected string $projectRoot;

    protected ?string $boardPath = null;

    protected ?string $reviewFilePath = null;

    public function __construct(Console $console, bool $dryRun, string $projectRoot)
    {
        $this->console = $console;
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

    protected function requireWorkflowRole(): string
    {
        $role = strtolower(trim((string) getenv(self::ENV_ACTIVE_ROLE)));
        if (!in_array($role, [self::ROLE_MANAGER, self::ROLE_DEVELOPER], true)) {
            throw new \RuntimeException(sprintf(
                'Assignment commands require %s=manager or %s=developer.',
                self::ENV_ACTIVE_ROLE,
                self::ENV_ACTIVE_ROLE,
            ));
        }

        return $role;
    }

    protected function requireWorkflowAgent(): string
    {
        $agent = trim((string) getenv(self::ENV_ACTIVE_AGENT));
        if ($agent === '') {
            throw new \RuntimeException(sprintf(
                'Developer assignment commands require %s=<code>.',
                self::ENV_ACTIVE_AGENT,
            ));
        }

        return $agent;
    }

    protected function assertCanAssignFeature(
        string $actorRole,
        ?string $actorAgent,
        string $targetAgent,
        string $feature,
        BacklogBoard $board,
        BacklogEntryResolver $entryResolver
    ): void {
        if ($actorRole === self::ROLE_MANAGER) {
            return;
        }

        if ($actorAgent !== $targetAgent) {
            throw new \RuntimeException(sprintf(
                'Developer role can only assign itself. %s must match --agent.',
                self::ENV_ACTIVE_AGENT,
            ));
        }

        $match = $entryResolver->requireFeature($board, $feature);
        $assignedAgent = $match->getEntry()->getAgent();
        if ($assignedAgent !== null && $assignedAgent !== $actorAgent) {
            throw new \RuntimeException(sprintf(
                'Feature %s is already assigned to %s. Only manager can reassign it.',
                $feature,
                $assignedAgent,
            ));
        }
    }

    protected function assertCanUnassignFeature(
        string $actorRole,
        ?string $actorAgent,
        string $targetAgent,
        string $feature,
        BoardEntry $entry
    ): void {
        if ($actorRole === self::ROLE_MANAGER) {
            return;
        }

        if ($actorAgent !== $targetAgent) {
            throw new \RuntimeException(sprintf(
                'Developer role can only unassign itself. %s must match --agent.',
                self::ENV_ACTIVE_AGENT,
            ));
        }

        $assignedAgent = $entry->getAgent();
        if ($assignedAgent !== $actorAgent) {
            throw new \RuntimeException(sprintf(
                'Feature %s is assigned to %s. Developer role can only unassign its own feature.',
                $feature,
                $assignedAgent === null ? 'no agent' : $assignedAgent,
            ));
        }
    }
}
