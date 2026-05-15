<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Client;

/**
 * Delegates reviewer workflow transitions to the backlog script under the global mutation lock.
 *
 * Implementations must run the underlying backlog.php commands with the correct SOMANAGER_ROLE
 * and SOMANAGER_AGENT environment so that stage transitions go through the same revalidation
 * and file lock path used by every other reviewer workflow command.
 */
interface BacklogCommandRunner
{
    /**
     * Claims a review entry for the reviewer, transitioning it from review to reviewing.
     *
     * Equivalent to:
     *   SOMANAGER_ROLE=reviewer SOMANAGER_AGENT=<reviewerCode> php scripts/backlog.php review-next <entryRef>
     *
     * @throws \RuntimeException when the entry cannot be claimed (already taken, not found, wrong stage, etc.)
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
}
