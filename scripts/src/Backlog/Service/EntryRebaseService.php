<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Service\GitService;

/**
 * Rebases an active backlog entry branch onto its canonical target branch.
 *
 * Reused by both the CLI command {@see \SoManAgent\Script\Backlog\Command\BacklogEntryRebaseCommand}
 * and the agent launcher ({@see \SoManAgent\Script\Backlog\Agent\Command\AgentStartCommand}) in
 * automatic mode when a developer has an approved entry.
 */
class EntryRebaseService
{
    private BacklogBoardService $boardService;

    private GitService $gitService;

    /**
     * @param BacklogBoardService $boardService
     * @param GitService $gitService
     */
    public function __construct(BacklogBoardService $boardService, GitService $gitService)
    {
        $this->boardService = $boardService;
        $this->gitService = $gitService;
    }

    /**
     * Rebases the entry branch onto its canonical target and pushes on success.
     *
     * Target branch resolution:
     * - Scoped task (meta.kind=task with meta.feature-branch): target = parent feature branch (local)
     * - Root feature: target = origin/main (fetched before rebasing)
     *
     * Push policy:
     * - Feature branches are pushed with --force-with-lease after a successful rebase.
     * - Task branches are local-only and are never pushed.
     *
     * On conflict the rebase is left in progress so the agent or operator can resolve the
     * conflicts and continue. Callers must not call {@see EntryRebaseResult::isConflict()} and
     * then expect the worktree to be clean — it will remain in a "rebase in progress" state.
     *
     * @param BoardEntry $entry The active board entry to rebase
     * @param string $worktree Absolute path to the worktree where the branch is checked out
     * @return EntryRebaseResult
     * @throws \RuntimeException When mandatory metadata is missing from the entry
     */
    public function rebase(BoardEntry $entry, string $worktree): EntryRebaseResult
    {
        $sourceBranch = $entry->getBranch();
        if ($sourceBranch === null || $sourceBranch === '') {
            throw new \RuntimeException('Cannot rebase: entry metadata is missing branch.');
        }

        $isTask = $this->boardService->checkIsTaskEntry($entry);

        if ($isTask) {
            $targetBranch = $entry->getFeatureBranch();
            if ($targetBranch === null || $targetBranch === '') {
                throw new \RuntimeException('Cannot rebase task: entry metadata is missing feature-branch.');
            }
        } else {
            $this->gitService->updateMainBranch();
            $targetBranch = GitService::ORIGIN_REMOTE . '/' . GitService::MAIN_BRANCH;
        }

        if ($this->gitService->checkIsAncestor($targetBranch, $sourceBranch)) {
            return EntryRebaseResult::upToDate($targetBranch);
        }

        $conflictFiles = $this->gitService->tryRebaseInPath($worktree, $targetBranch);
        if ($conflictFiles !== []) {
            return EntryRebaseResult::conflict($targetBranch, $conflictFiles);
        }

        if (!$isTask) {
            $this->gitService->pushBranchSafely($sourceBranch, GitService::ORIGIN_REMOTE, $worktree);
        }

        return EntryRebaseResult::rebased($targetBranch);
    }
}
