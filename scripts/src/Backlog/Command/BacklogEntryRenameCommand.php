<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntryMatch;
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
    private const ROLE_MANAGER = 'manager';

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
     * @param array<string, bool|string> $options
     */
    public function handle(array $commandArgs, array $options): void
    {
        $agent = $this->requireCallerAgent();
        $isManager = $this->readCallerRole() === self::ROLE_MANAGER;
        $board = $this->loadBoard();

        if ($isManager) {
            $reference = $this->boardService->sanitizeString($commandArgs[0] ?? null);
            if ($reference === null) {
                throw new \RuntimeException('rename requires an explicit <entry-ref> when SOMANAGER_ROLE=manager.');
            }
            $newText = $this->boardService->sanitizeString(implode(' ', array_slice($commandArgs, 1)));
            if ($newText === null || $newText === '') {
                throw new \RuntimeException('rename requires a new text as argument.');
            }
            $current = $this->boardService->resolveActiveEntryByReference($board, $reference, BacklogCommandName::RENAME->value);
        } else {
            [$current, $newText] = $this->resolveCallerEntryAndText($board, $agent, $commandArgs);
        }

        if ($newText === null || $newText === '') {
            throw new \RuntimeException('rename requires a new text as argument.');
        }

        $entry = $current->getEntry();
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

        $this->saveBoard($board, BacklogCommandName::RENAME->value);

        $this->presenter->displaySuccess(sprintf(
            '%s %s renamed: %s → %s',
            ucfirst($kind),
            $entry->getTask() ?? $entry->getFeature() ?? '-',
            $old,
            $newText,
        ));
    }

    /**
     * @param list<string> $commandArgs
     * @return array{0: BoardEntryMatch, 1: ?string}
     */
    private function resolveCallerEntryAndText(BacklogBoard $board, string $agent, array $commandArgs): array
    {
        $firstArgument = $this->boardService->sanitizeString($commandArgs[0] ?? null);
        if ($firstArgument !== null && count($commandArgs) > 1) {
            $explicit = $this->findActiveEntryByReferenceIfPresent($board, $firstArgument);
            if ($explicit !== null) {
                if ($explicit->getEntry()->getDeveloper() !== $agent) {
                    throw new \RuntimeException(sprintf(
                        'Entry %s is not assigned to caller agent %s.',
                        $this->boardService->getEntryReference($explicit->getEntry()),
                        $agent,
                    ));
                }

                return [$explicit, $this->boardService->sanitizeString(implode(' ', array_slice($commandArgs, 1)))];
            }
        }

        $activeEntries = $this->boardService->findActiveEntriesByAgent($board, $agent);
        if ($activeEntries === []) {
            throw new \RuntimeException(
                "Agent {$agent} has no active entry.\n" .
                "Run `php scripts/backlog.php start --agent={$agent}` to start one."
            );
        }

        return [$activeEntries[0], $this->boardService->sanitizeString(implode(' ', $commandArgs))];
    }

    private function findActiveEntryByReferenceIfPresent(BacklogBoard $board, string $reference): ?BoardEntryMatch
    {
        try {
            return $this->boardService->resolveActiveEntryByReference($board, $reference, BacklogCommandName::RENAME->value);
        } catch (\RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), 'No active entry found for reference:')) {
                return null;
            }

            throw $exception;
        }
    }
}
