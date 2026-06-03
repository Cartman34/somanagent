<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Service;

use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;

/**
 * Contract for rebasing an active backlog entry branch onto its canonical target branch.
 */
interface EntryRebaseServiceInterface
{
    /**
     * Rebases the entry branch onto its canonical target and pushes on success.
     *
     * @param BoardEntry $entry The active board entry to rebase
     * @param string $worktree Absolute path to the worktree where the branch is checked out
     * @param BacklogBoard|null $board When provided, meta.base is refreshed on the board on success
     * @return EntryRebaseResult
     * @throws \RuntimeException When mandatory metadata is missing from the entry
     */
    public function rebase(BoardEntry $entry, string $worktree, ?BacklogBoard $board = null): EntryRebaseResult;
}
