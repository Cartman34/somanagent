<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Test;

use Sowapps\SoManAgent\Script\Backlog\Service\EntryRebaseService;
use Sowapps\SoManAgent\Script\Backlog\Service\EntryRebaseResult;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;

/**
 * Test double for {@see EntryRebaseService}.
 *
 * Returns a pre-configured result without performing any git operations.
 */
final class FakeEntryRebaseService extends EntryRebaseService
{
    /**
     * Arguments captured by the last rebase() call.
     *
     * @var array{entry: BoardEntry, worktree: string}|null
     */
    public ?array $lastCall = null;

    private EntryRebaseResult $result;

    /**
     * @param EntryRebaseResult $result Result to return from rebase()
     */
    public function __construct(EntryRebaseResult $result)
    {
        // parent __construct skipped intentionally — no real git operations needed
        $this->result = $result;
    }

    /**
     * @param BoardEntry $entry
     * @param string $worktree
     * @param BacklogBoard|null $board
     * @return EntryRebaseResult
     */
    public function rebase(BoardEntry $entry, string $worktree, ?BacklogBoard $board = null): EntryRebaseResult
    {
        $this->lastCall = ['entry' => $entry, 'worktree' => $worktree];

        return $this->result;
    }
}
