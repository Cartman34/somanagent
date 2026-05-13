<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;

/**
 * Sets or clears a named extra-metadata key on the caller agent's active entry.
 *
 * Only the allowed extra-metadata keys listed in ALLOWED_KEYS may be written.
 * Pass an empty value to clear the key.
 *
 * @see BoardEntry::getExtraMetadata()
 */
final class BacklogEntrySetMetaCommand extends AbstractBacklogCommand
{
    /**
     * Extra-metadata keys that this command is allowed to write or clear.
     *
     * @var list<string>
     */
    private const ALLOWED_KEYS = ['database'];

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string|array<bool|string>> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $agent = $this->requireCallerAgent();

        $assignment = $commandArgs[0] ?? '';
        if (!str_contains($assignment, '=')) {
            throw new \RuntimeException(
                'entry-set-meta requires a key=value argument. Example: entry-set-meta database=my_db_name'
            );
        }

        [$key, $value] = explode('=', $assignment, 2);
        $key = trim($key);
        $value = trim($value);

        if (!in_array($key, self::ALLOWED_KEYS, true)) {
            throw new \RuntimeException(sprintf(
                'entry-set-meta does not support key "%s". Allowed keys: %s.',
                $key,
                implode(', ', self::ALLOWED_KEYS),
            ));
        }

        $board = $this->loadBoard();
        $activeEntries = $this->boardService->findActiveEntriesByAgent($board, $agent);

        if ($activeEntries === []) {
            throw new \RuntimeException(
                "Agent {$agent} has no active entry.\n" .
                "Run `php scripts/backlog.php work-start` to start one."
            );
        }

        $entry = $activeEntries[0]->getEntry();
        $extra = $entry->getExtraMetadata();

        if ($value === '') {
            unset($extra[$key]);
            $action = "cleared";
        } else {
            $extra[$key] = $value;
            $action = "set to {$value}";
        }

        $entry->setExtraMetadata($extra);

        $this->saveBoard($board, BacklogCommandName::ENTRY_SET_META->value);

        $this->presenter->displaySuccess(sprintf(
            'Entry %s: meta.%s %s.',
            $entry->getFeature() ?? $entry->getTask() ?? '-',
            $key,
            $action,
        ));
    }
}
