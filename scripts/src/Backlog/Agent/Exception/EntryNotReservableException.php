<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Exception;

/**
 * Thrown when a backlog entry cannot be reserved because it was already claimed,
 * transitioned to another stage, or disappeared from the queue since the list was read.
 *
 * This is a well-known contention case in concurrent agent launches. Selectors catch
 * it to silently skip the affected entry and try the next candidate in the list.
 * Any other exception type propagates as a real error.
 */
final class EntryNotReservableException extends \RuntimeException
{
    private string $entryRef;

    /**
     * @param string $entryRef The reference of the entry that could not be reserved
     * @param string $detail   The error output from the backlog command
     */
    public function __construct(string $entryRef, string $detail)
    {
        $this->entryRef = $entryRef;

        parent::__construct(sprintf(
            "Entry '%s' is not reservable: %s",
            $entryRef,
            $detail,
        ));
    }

    /**
     * Returns the entry reference that could not be reserved.
     */
    public function getEntryRef(): string
    {
        return $this->entryRef;
    }
}
