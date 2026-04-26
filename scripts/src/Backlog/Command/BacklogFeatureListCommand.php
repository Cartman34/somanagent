<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\Console;

/**
 * Command for listing active features.
 */
final class BacklogFeatureListCommand extends AbstractBacklogCommand
{
    private BacklogEntryService $entryService;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryService $entryService
    ) {
        parent::__construct($console, $dryRun, $projectRoot);
        $this->entryService = $entryService;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $printed = false;
        foreach (BacklogBoard::activeStages() as $stage) {
            $entries = array_values(array_filter(
                $board->getEntries(BacklogBoard::SECTION_ACTIVE),
                fn(BoardEntry $entry): bool => $this->entryService->featureStage($entry) === $stage
            ));
            if ($entries === []) {
                continue;
            }

            $printed = true;
            $this->console->line('[' . BacklogBoard::stageLabel($stage) . ']');
            foreach ($entries as $entry) {
                $parts = [
                    'kind=' . $this->entryService->entryKind($entry),
                    $entry->getFeature() ?? '-',
                    'branch=' . ($entry->getBranch() ?? '-'),
                    'agent=' . ($entry->getAgent() ?? '-'),
                ];
                if ($this->entryService->isTaskEntry($entry)) {
                    $parts[] = 'task=' . ($entry->getTask() ?? '-');
                    $parts[] = 'feature-branch=' . ($entry->getFeatureBranch() ?? '-');
                }
                if ($entry->isBlocked()) {
                    $parts[] = 'blocked=yes';
                }
                $this->console->line('- ' . implode(' ', $parts));
            }
        }

        if (!$printed) {
            $this->console->line('No active feature.');
        }
    }
}
