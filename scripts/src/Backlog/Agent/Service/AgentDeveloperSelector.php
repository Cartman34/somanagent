<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Service;

use SoManAgent\Script\Backlog\Agent\Client\BacklogCommandRunner;
use SoManAgent\Script\Backlog\Agent\Exception\EntryNotReservableException;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntryMatch;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;

/**
 * Selects the target backlog entry for a developer session launch.
 *
 * Handles auto-selection (first queued task) and detects when the developer
 * already has an active entry (resume case — no auto-pick needed).
 * Symmetric to {@see AgentReviewerSelector} on the developer side.
 */
final class AgentDeveloperSelector
{
    private BacklogBoardService $boardService;

    /**
     * @param BacklogBoardService $boardService
     */
    public function __construct(BacklogBoardService $boardService)
    {
        $this->boardService = $boardService;
    }

    /**
     * Returns the active entry already owned by the developer, or null.
     *
     * When non-null, start must not auto-pick: the developer resumes current work.
     */
    public function findOwnedActiveEntry(BacklogBoard $board, string $developerCode): ?BoardEntryMatch
    {
        $entries = $this->boardService->findActiveEntriesByAgent($board, $developerCode);

        return $entries[0] ?? null;
    }

    /**
     * Returns the stable entry reference of the first queued task.
     *
     * @throws \RuntimeException when the todo list is empty for this developer
     */
    public function selectFirstQueued(BacklogBoard $board, string $developerCode): string
    {
        $entries = $board->getEntries(BacklogBoard::SECTION_TODO);
        $first = reset($entries);
        if ($first === false) {
            throw new \RuntimeException(sprintf(
                'No queued task available for %s.',
                $developerCode,
            ));
        }

        return $this->boardService->computeQueuedEntryReference($first);
    }

    /**
     * Auto-picks and reserves the first available queued task by looping the todo list.
     *
     * For each candidate, attempts start via the runner. An {@see EntryNotReservableException}
     * (entry claimed between read and mutation by a concurrent launch) is silently skipped.
     * Any other exception propagates immediately. Returns null when the list is exhausted.
     *
     * @throws \RuntimeException on an unexpected failure (not a contention case)
     */
    public function pick(BacklogBoard $board, string $developerCode, BacklogCommandRunner $runner): ?string
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_TODO) as $entry) {
            $ref = $this->boardService->computeQueuedEntryReference($entry);
            try {
                $runner->workStart($developerCode, $ref);

                return $ref;
            } catch (EntryNotReservableException) {
                continue;
            }
        }

        return null;
    }
}
