<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Service;

use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\SessionDriverInterface;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Model\BoardEntry;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
/**
 * Notifies a reviewer's live tmux session after a review-request transition.
 *
 * Called by BacklogReviewRequestCommand after an entry successfully transitions
 * to the `review` stage. When the entry has a registered reviewer with a live
 * tmux session, this service injects a prompt asking the reviewer to pick up
 * the entry via review-next.
 *
 * All skip conditions (absent session, dead session, non-reviewer role, non-tmux driver,
 * incoherent worktree, injection failure) are handled silently: the method always
 * returns without throwing so the review-request command result is unaffected.
 *
 * Feature enabled when:
 *   - The board carries `config.review_resume.enabled: true`, AND
 *   - The reviewer session has no override (reviewResume = null), OR
 *   - The reviewer session explicitly sets reviewResume = true (overrides the board).
 * Feature disabled when:
 *   - Board config is absent or false AND session override is null or false.
 */
final class ReviewResumeNotifier
{
    private AgentSessionService $sessionService;

    private SessionDriverInterface $sessionDriver;

    private BacklogBoardService $boardService;

    private string $worktreesRoot;

    /**
     * @param AgentSessionService $sessionService
     * @param SessionDriverInterface $sessionDriver
     * @param BacklogBoardService $boardService
     * @param string $worktreesRoot Absolute path to the managed worktrees directory
     */
    public function __construct(
        AgentSessionService $sessionService,
        SessionDriverInterface $sessionDriver,
        BacklogBoardService $boardService,
        string $worktreesRoot,
    ) {
        $this->sessionService = $sessionService;
        $this->sessionDriver = $sessionDriver;
        $this->boardService = $boardService;
        $this->worktreesRoot = $worktreesRoot;
    }

    /**
     * Attempts to wake the registered reviewer for an entry that just moved to `review`.
     *
     * Silently skips when any prerequisite is unmet; never throws.
     */
    public function notify(BacklogBoard $board, BoardEntry $entry): void
    {
        try {
            $this->doNotify($board, $entry);
        } catch (\Throwable) {
            // Silent: review-request must succeed regardless of notification outcome.
        }
    }

    private function doNotify(BacklogBoard $board, BoardEntry $entry): void
    {
        $reviewer = $entry->getReviewer();
        if ($reviewer === null || $reviewer === '') {
            return;
        }

        $session = $this->sessionService->get($reviewer);
        if ($session === null) {
            return;
        }

        if (!$this->isEnabled($board, $session->reviewResume)) {
            return;
        }

        if (!$this->sessionDriver->isAlive($session)) {
            return;
        }

        if ($session->role !== AgentRole::REVIEWER) {
            return;
        }

        if ($session->tmuxSession === null || $session->tmuxSession === '') {
            return;
        }

        $developer = $entry->getDeveloper();
        if ($developer !== null && $developer !== '') {
            $expectedWorktree = rtrim($this->worktreesRoot, '/') . '/' . $developer;
            if ($session->worktree !== $expectedWorktree) {
                return;
            }
        }

        $entryRef = $this->boardService->getEntryReference($entry);
        $prompt = sprintf(
            'Run: review-next %s, then review-check %s, then review-approve or review-reject.',
            $entryRef,
            $entryRef,
        );

        $this->sessionDriver->injectPrompt($session, $prompt);
    }

    /**
     * Resolves whether the notification is enabled for this notification attempt.
     *
     * Session override wins over board config:
     * - sessionOverride = true  → enabled
     * - sessionOverride = false → disabled
     * - sessionOverride = null  → use board config (enabled only when explicitly true)
     */
    private function isEnabled(BacklogBoard $board, ?bool $sessionOverride): bool
    {
        if ($sessionOverride !== null) {
            return $sessionOverride;
        }

        return $board->getReviewResumeEnabled() === true;
    }
}
