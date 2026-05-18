<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Test;

use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\EntryRebaseResult;
use SoManAgent\Script\Backlog\Service\EntryRebaseService;

/**
 * Null-object test double for {@see EntryRebaseService}.
 *
 * Use this in tests that intentionally exercise code paths where approved-entry
 * rebase must not occur (e.g. stage=development, reviewer mode, manager mode).
 * If rebase() is accidentally called, a LogicException is thrown so the test
 * fails visibly rather than silently succeeding with wrong behavior.
 */
final class NullEntryRebaseService extends EntryRebaseService
{
    public function __construct()
    {
        // parent __construct skipped intentionally — no real services needed
    }

    /**
     * @throws \LogicException always — rebase must not be called in this test context
     */
    public function rebase(BoardEntry $entry, string $worktree, ?BacklogBoard $board = null): EntryRebaseResult
    {
        throw new \LogicException('NullEntryRebaseService::rebase() must not be called in this test scenario.');
    }
}
