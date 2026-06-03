<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Model;

use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntryMatch;

/**
 * Outcome of the reviewer-mode preparation step.
 *
 * Three mutually exclusive states:
 *  - Normal start: worktree, takenEntryRef and takenReviewerCode describe the new session to launch.
 *  - Adopt: operator accepted an existing live reviewer session; only adoptSession is set.
 *  - Quit: operator aborted the picker; no session is started.
 *
 * The takenEntryRef / takenReviewerCode pair is also populated for the adopt state
 * so that rollbackReviewTransition() can cancel the review-next call on failure.
 */
final class ReviewerPickOutcome
{
    /**
     * @param string|null $worktree Absolute path to the developer WA (normal state only)
     * @param string|null $takenEntryRef Entry ref used in the review-next call (normal and adopt)
     * @param string|null $takenReviewerCode Reviewer code used in the review-next call (normal and adopt)
     * @param AgentSession|null $adoptSession Existing session to re-attach to (adopt state only)
     * @param bool $quit True when the operator chose to abort
     * @param BoardEntryMatch|null $takenMatch Board match for the taken entry before board reload (auto-pick path only)
     */
    private function __construct(
        private readonly ?string $worktree,
        private readonly ?string $takenEntryRef,
        private readonly ?string $takenReviewerCode,
        private readonly ?AgentSession $adoptSession,
        private readonly bool $quit,
        private readonly ?BoardEntryMatch $takenMatch = null,
    ) {}

    /**
     * Normal state: a new reviewer session should be launched on the given worktree.
     */
    public static function normal(string $worktree, ?string $takenEntryRef, ?string $takenReviewerCode): self
    {
        return new self($worktree, $takenEntryRef, $takenReviewerCode, null, false);
    }

    /**
     * Normal state with a pre-reload board match (used by the auto-pick path).
     *
     * The $takenMatch provides the entry before the board is reloaded, serving as a fallback
     * for worktree reconstruction when findOwnedReviewingEntry() returns null after reload.
     */
    public static function normalWithMatch(string $worktree, string $takenEntryRef, string $takenReviewerCode, BoardEntryMatch $takenMatch): self
    {
        return new self($worktree, $takenEntryRef, $takenReviewerCode, null, false, $takenMatch);
    }

    /**
     * Adopt state: the operator accepted the existing live reviewer session.
     *
     * The entry was assigned (review-next called with $existingReviewerCode) before returning.
     * $takenEntryRef and $existingReviewerCode are kept for rollback on attach failure.
     */
    public static function adopt(AgentSession $session, string $takenEntryRef, string $existingReviewerCode): self
    {
        return new self(null, $takenEntryRef, $existingReviewerCode, $session, false);
    }

    /**
     * Quit state: the operator aborted the picker, no session should be started.
     */
    public static function quit(): self
    {
        return new self(null, null, null, null, true);
    }

    /**
     * Returns true when the operator aborted the picker.
     */
    public function isQuit(): bool
    {
        return $this->quit;
    }

    /**
     * Returns true when the outcome is to attach to an existing reviewer session.
     */
    public function isAdopt(): bool
    {
        return $this->adoptSession !== null;
    }

    /**
     * Returns the developer WA path for the normal-start state.
     *
     * @throws \LogicException when called on a non-normal outcome
     */
    public function getWorktree(): string
    {
        if ($this->worktree === null) {
            throw new \LogicException('getWorktree() called on a non-normal ReviewerPickOutcome.');
        }

        return $this->worktree;
    }

    /**
     * Returns the entry ref used in review-next, or null when no entry was taken.
     */
    public function getTakenEntryRef(): ?string
    {
        return $this->takenEntryRef;
    }

    /**
     * Returns the reviewer code used in review-next, or null when no entry was taken.
     */
    public function getTakenReviewerCode(): ?string
    {
        return $this->takenReviewerCode;
    }

    /**
     * Returns the pre-reload board match, or null when not set.
     *
     * Used by the auto-pick path as a fallback for worktree reconstruction when
     * findOwnedReviewingEntry() returns null after the board reload.
     */
    public function getTakenMatch(): ?BoardEntryMatch
    {
        return $this->takenMatch;
    }

    /**
     * Returns the existing reviewer session to re-attach to.
     *
     * @throws \LogicException when called on a non-adopt outcome
     */
    public function getAdoptSession(): AgentSession
    {
        if ($this->adoptSession === null) {
            throw new \LogicException('getAdoptSession() called on a non-adopt ReviewerPickOutcome.');
        }

        return $this->adoptSession;
    }
}
