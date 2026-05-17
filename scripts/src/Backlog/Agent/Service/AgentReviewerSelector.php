<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Service;

use SoManAgent\Script\Backlog\Agent\Client\BacklogCommandRunner;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Exception\EntryNotReservableException;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Model\BoardEntryMatch;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;

/**
 * Selects the target backlog entry for a reviewer session launch.
 *
 * Handles auto-selection (first review-stage entry not yet claimed) and
 * explicit targeting via --feature, --task, or --developer flags.
 * Also detects concurrent reviewer-vs-reviewer session conflicts.
 */
final class AgentReviewerSelector
{
    private BacklogBoardService $boardService;
    private AgentSessionService $sessionService;
    private string $worktreesRoot;

    /**
     * @param BacklogBoardService $boardService
     * @param AgentSessionService $sessionService
     * @param string $worktreesRoot Absolute path to the managed worktrees directory
     */
    public function __construct(
        BacklogBoardService $boardService,
        AgentSessionService $sessionService,
        string $worktreesRoot,
    ) {
        $this->boardService = $boardService;
        $this->sessionService = $sessionService;
        $this->worktreesRoot = $worktreesRoot;
    }

    /**
     * Returns the entry already owned by the reviewer at stage=reviewing, or null.
     *
     * Used as the first step in start resolution to allow resuming an in-progress review.
     */
    public function findOwnedReviewingEntry(BacklogBoard $board, string $reviewerCode): ?BoardEntryMatch
    {
        return $this->boardService->findReviewingEntryByReviewer($board, $reviewerCode);
    }

    /**
     * Auto-selects the first review-stage entry whose developer WA is not already claimed by a reviewer.
     *
     * Only considers stage=review entries; reviewing entries are owned by other reviewers and skipped.
     *
     * @throws \RuntimeException when no free review-stage entry is found
     */
    public function autoSelect(BacklogBoard $board): BoardEntryMatch
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if ($this->boardService->getNormalizedStage($entry->getStage()) !== BacklogBoard::STAGE_IN_REVIEW) {
                continue;
            }
            $devCode = $entry->getAgent() ?? '';
            if ($devCode === '') {
                continue;
            }
            $worktree = $this->devCodeToWorktree($devCode);
            if ($this->findExistingReviewerForWorktree($worktree) !== null) {
                continue;
            }

            return new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        throw new \RuntimeException(
            'No review available. All review-stage entries are already being reviewed or no entry is ready for review.',
        );
    }

    /**
     * Selects the feature entry at stage=review (or stage=reviewing for this reviewer) matching the given slug.
     *
     * @throws \RuntimeException when no matching entry is found, or it is being reviewed by another reviewer
     */
    public function selectByFeature(BacklogBoard $board, string $feature, string $reviewerCode): BoardEntryMatch
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->boardService->checkIsFeatureEntry($entry)) {
                continue;
            }
            if ($entry->getFeature() !== $feature) {
                continue;
            }
            $stage = $this->boardService->getNormalizedStage($entry->getStage());
            if ($stage !== BacklogBoard::STAGE_IN_REVIEW && $stage !== BacklogBoard::STAGE_REVIEWING) {
                continue;
            }
            if ($stage === BacklogBoard::STAGE_REVIEWING && $entry->getReviewer() !== $reviewerCode) {
                throw new \RuntimeException(sprintf(
                    "Feature '%s' is already being reviewed by %s.",
                    $feature,
                    $entry->getReviewer() ?? 'another reviewer',
                ));
            }

            return new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        throw new \RuntimeException(sprintf(
            "No feature entry at stage=review or stage=reviewing found for '%s'.",
            $feature,
        ));
    }

    /**
     * Selects the task entry at stage=review (or stage=reviewing for this reviewer) matching the ref.
     *
     * @throws \RuntimeException when the ref format is invalid or no matching entry is found
     */
    public function selectByTask(BacklogBoard $board, string $ref, string $reviewerCode): BoardEntryMatch
    {
        $parts = explode('/', $ref, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \RuntimeException(sprintf(
                "Invalid task reference '%s'. Expected format: <feature>/<task>.",
                $ref,
            ));
        }
        [$featureSlug, $taskSlug] = $parts;

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if (!$this->boardService->checkIsTaskEntry($entry)) {
                continue;
            }
            if ($entry->getFeature() !== $featureSlug || $entry->getTask() !== $taskSlug) {
                continue;
            }
            $stage = $this->boardService->getNormalizedStage($entry->getStage());
            if ($stage !== BacklogBoard::STAGE_IN_REVIEW && $stage !== BacklogBoard::STAGE_REVIEWING) {
                continue;
            }
            if ($stage === BacklogBoard::STAGE_REVIEWING && $entry->getReviewer() !== $reviewerCode) {
                throw new \RuntimeException(sprintf(
                    "Task '%s' is already being reviewed by %s.",
                    $ref,
                    $entry->getReviewer() ?? 'another reviewer',
                ));
            }

            return new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        throw new \RuntimeException(sprintf(
            "No task entry at stage=review or stage=reviewing found for '%s'.",
            $ref,
        ));
    }

    /**
     * Selects the single active reviewable entry assigned to the given developer code.
     *
     * @throws \RuntimeException when no active reviewable entry is found for that developer
     */
    public function selectByDeveloper(BacklogBoard $board, string $developerCode, string $reviewerCode): BoardEntryMatch
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if ($entry->getAgent() !== $developerCode) {
                continue;
            }
            $stage = $this->boardService->getNormalizedStage($entry->getStage());
            if ($stage !== BacklogBoard::STAGE_IN_REVIEW && $stage !== BacklogBoard::STAGE_REVIEWING) {
                continue;
            }
            if ($stage === BacklogBoard::STAGE_REVIEWING && $entry->getReviewer() !== $reviewerCode) {
                throw new \RuntimeException(sprintf(
                    "Developer %s's entry is already being reviewed by %s.",
                    $developerCode,
                    $entry->getReviewer() ?? 'another reviewer',
                ));
            }

            return new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
        }

        throw new \RuntimeException(sprintf(
            "Developer '%s' has no active entry at stage=review or stage=reviewing.",
            $developerCode,
        ));
    }

    /**
     * Auto-picks and claims the first available review-stage entry by looping the active list.
     *
     * Applies the same worktree-session filter as {@see autoSelect} (skips entries whose
     * developer WA is already targeted by a running reviewer session). For each remaining
     * candidate, attempts review-next via the runner. An {@see EntryNotReservableException}
     * (entry claimed between read and mutation by a concurrent launch) is silently skipped.
     * Any other exception propagates immediately. Returns null when the list is exhausted.
     *
     * The returned {@see BoardEntryMatch} reflects the entry state before review-next; the
     * caller is responsible for reloading the board to get the updated stage.
     *
     * @throws \RuntimeException on an unexpected failure (not a contention case)
     */
    public function pick(BacklogBoard $board, string $reviewerCode, BacklogCommandRunner $runner): ?BoardEntryMatch
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if ($this->boardService->getNormalizedStage($entry->getStage()) !== BacklogBoard::STAGE_IN_REVIEW) {
                continue;
            }
            $devCode = $entry->getAgent() ?? '';
            if ($devCode === '') {
                continue;
            }
            $worktree = $this->devCodeToWorktree($devCode);
            if ($this->findExistingReviewerForWorktree($worktree) !== null) {
                continue;
            }

            $entryRef = $this->buildEntryRef($entry);
            try {
                $runner->reviewNext($reviewerCode, $entryRef);

                return new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry);
            } catch (EntryNotReservableException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Returns the reviewer session already using the given worktree path, or null.
     */
    public function findExistingReviewerForWorktree(string $worktree): ?AgentSession
    {
        foreach ($this->sessionService->load() as $session) {
            if ($session->role === AgentRole::REVIEWER && $session->worktree === $worktree) {
                return $session;
            }
        }

        return null;
    }

    /**
     * Derives the absolute worktree path from a developer agent code.
     */
    public function devCodeToWorktree(string $devCode): string
    {
        return $this->worktreesRoot . '/' . $devCode;
    }

    /**
     * Builds the stable entry reference used by review-next commands.
     *
     * Tasks use the "feature/task" format; features use the feature slug.
     */
    private function buildEntryRef(BoardEntry $entry): string
    {
        if ($this->boardService->checkIsTaskEntry($entry)) {
            return $this->boardService->getTaskReviewKey($entry);
        }

        return $entry->getFeature() ?? '';
    }
}
