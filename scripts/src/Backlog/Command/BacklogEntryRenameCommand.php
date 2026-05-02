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
 * Renames the active entry text for the given agent.
 *
 * Works for both kind=task and kind=feature entries assigned to the agent.
 * For kind=task, also updates the matching contribution line in the parent feature container.
 */
final class BacklogEntryRenameCommand extends AbstractBacklogCommand
{
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
     * @param array<string, bool|string> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }

        $newText = $this->boardService->sanitizeString(implode(' ', $commandArgs));
        if ($newText === null || $newText === '') {
            throw new \RuntimeException('entry-rename requires a new text as argument.');
        }

        $board = $this->loadBoard();
        $activeEntries = $this->boardService->findActiveEntriesByAgent($board, $agent);

        if ($activeEntries === []) {
            throw new \RuntimeException(
                "Agent {$agent} has no active entry.\n" .
                "Run `php scripts/backlog.php work-start --agent={$agent}` to start one."
            );
        }

        $entry = $activeEntries[0]->getEntry();
        $kind = $this->boardService->getEntryKind($entry);
        $old = $entry->getText();
        $entry->setText($newText);

        if ($this->boardService->checkIsTaskEntry($entry)) {
            $feature = $entry->getFeature();
            $task = $entry->getTask();
            if ($feature !== null && $task !== null) {
                $parent = $this->boardService->findParentFeatureEntry($board, $feature);
                if ($parent !== null) {
                    $this->boardService->updateTaskContributionText($parent->getEntry(), $task, $newText);
                }
            }
        }

        $this->saveBoard($board, BacklogCommandName::ENTRY_RENAME->value);

        $this->presenter->displaySuccess(sprintf(
            '%s %s renamed: %s → %s',
            ucfirst($kind),
            $entry->getTask() ?? $entry->getFeature() ?? '-',
            $old,
            $newText,
        ));
    }
}
