<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Client;

use Sowapps\SoManAgent\Script\Backlog\Agent\Exception\EntryNotReservableException;

/**
 * Delegates reviewer and developer workflow transitions to the backlog script under the global mutation lock.
 *
 * Implementations must run the underlying backlog.php commands with the correct SOMANAGER_ROLE
 * and SOMANAGER_AGENT environment so that stage transitions go through the same revalidation
 * and file lock path used by every other workflow command.
 */
interface BacklogCommandRunner
{
    /**
     * Claims a review entry for the reviewer, transitioning it from review to reviewing.
     *
     * Equivalent to:
     *   SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=<reviewerCode> php scripts/backlog.php review-next <entryRef>
     *
     * @throws EntryNotReservableException when the entry is already
     *         claimed, transitioned, or not found — expected under concurrent reviewer launches
     * @throws \RuntimeException on any other failure (filesystem, registry, unexpected exit code)
     */
    public function reviewNext(string $reviewerCode, string $entryRef): void;

    /**
     * Releases a reviewing entry back to review stage, clearing the reviewer.
     *
     * Equivalent to:
     *   SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=<reviewerCode> php scripts/backlog.php review-cancel <entryRef>
     *
     * @throws \RuntimeException when the release fails.
     */
    public function reviewCancel(string $reviewerCode, string $entryRef): void;

    /**
     * Starts the developer's next queued task, transitioning it from todo to in-progress.
     *
     * Equivalent to:
     *   SOMANAGER_ROLE=developer SOMANAGER_AGENT=<developerCode> php scripts/backlog.php start <entryRef>
     *
     * @throws EntryNotReservableException when the entry is no longer
     *         in the todo queue — expected under concurrent developer launches
     * @throws \RuntimeException on any other failure (filesystem, registry, unexpected exit code)
     */
    public function workStart(string $developerCode, string $entryRef): void;

    /**
     * Releases an untouched entry back to the todo queue, rolling back a start.
     *
     * Equivalent to:
     *   SOMANAGER_ROLE=developer SOMANAGER_AGENT=<developerCode> php scripts/backlog.php release <entryRef>
     *
     * @throws \RuntimeException when release fails
     */
    public function entryRelease(string $developerCode, string $entryRef): void;
}
