<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Service;

/**
 * Outcome of {@see EntryRebaseService::rebase()}.
 *
 * Three mutually exclusive states:
 * - up_to_date : the source branch already contains the target — no rebase performed
 * - rebased    : rebase succeeded and the branch was pushed
 * - conflict   : rebase stopped on conflict; the worktree is left in "rebase in progress" state
 */
final class EntryRebaseResult
{
    private const STATUS_UP_TO_DATE = 'up_to_date';
    private const STATUS_REBASED = 'rebased';
    private const STATUS_CONFLICT = 'conflict';

    private string $status;

    private string $targetBranch;

    /**
     * @var list<string>|null
     */
    private ?array $conflictFiles;

    /**
     * @param list<string>|null $conflictFiles
     */
    private function __construct(string $status, string $targetBranch, ?array $conflictFiles)
    {
        $this->status = $status;
        $this->targetBranch = $targetBranch;
        $this->conflictFiles = $conflictFiles;
    }

    /**
     * @param string $targetBranch
     * @return self
     */
    public static function upToDate(string $targetBranch): self
    {
        return new self(self::STATUS_UP_TO_DATE, $targetBranch, null);
    }

    /**
     * @param string $targetBranch
     * @return self
     */
    public static function rebased(string $targetBranch): self
    {
        return new self(self::STATUS_REBASED, $targetBranch, null);
    }

    /**
     * @param string $targetBranch
     * @param list<string> $conflictFiles
     * @return self
     */
    public static function conflict(string $targetBranch, array $conflictFiles): self
    {
        return new self(self::STATUS_CONFLICT, $targetBranch, $conflictFiles);
    }

    /**
     * @return bool
     */
    public function isUpToDate(): bool
    {
        return $this->status === self::STATUS_UP_TO_DATE;
    }

    /**
     * @return bool
     */
    public function isRebased(): bool
    {
        return $this->status === self::STATUS_REBASED;
    }

    /**
     * @return bool
     */
    public function isConflict(): bool
    {
        return $this->status === self::STATUS_CONFLICT;
    }

    /**
     * @return string
     */
    public function getTargetBranch(): string
    {
        return $this->targetBranch;
    }

    /**
     * Returns a human-readable summary of the result.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return match ($this->status) {
            self::STATUS_UP_TO_DATE => sprintf('Already up to date with %s', $this->targetBranch),
            self::STATUS_REBASED => sprintf('Rebased on %s and pushed', $this->targetBranch),
            self::STATUS_CONFLICT => sprintf('Resolve conflicts then push manually (target: %s)', $this->targetBranch),
            default => throw new \LogicException(sprintf("Unknown rebase status: %s", $this->status)),
        };
    }

    /**
     * Returns the list of files with unresolved merge conflicts.
     *
     * Only meaningful when {@see isConflict()} is true.
     *
     * @return list<string>
     */
    public function getConflictFiles(): array
    {
        return $this->conflictFiles ?? [];
    }
}
