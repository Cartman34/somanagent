<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use RuntimeException;

/**
 * Command for reworking a rejected task or feature.
 */
final class BacklogReworkCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new RuntimeException('Option --agent is required.');
        }

        $board = $this->loadBoard();
        $reference = $commandArgs[0] ?? null;

        if ($reference !== null) {
            $entry = $this->resolveByReference($board, $reference, $agent);
        } else {
            $entry = $this->resolveRejectedEntryForAgent($board, $agent);
        }

        if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_REJECTED) {
            throw new RuntimeException(sprintf(
                'Entry %s is not in the rejected stage.',
                $this->describeEntry($entry),
            ));
        }

        $reviewKey = $this->boardService->checkIsTaskEntry($entry)
            ? $this->boardService->getTaskReviewKey($entry)
            : ($entry->getFeature() ?? '-');

        $review = $this->loadReviewFile();
        $notes = $review->getReviews()[$reviewKey] ?? [];

        $worktree = $this->worktreeService->prepareAgentWorktree($agent);
        $this->worktreeService->checkoutBranchInWorktree($worktree, $entry->getBranch() ?? '', false);

        $entry->setStage(BacklogBoard::STAGE_IN_PROGRESS);
        $this->saveBoard($board, BacklogCommandName::REWORK->value);

        if ($notes !== []) {
            $this->presenter->displayInfo('Review notes:');
            foreach ($notes as $note) {
                $this->presenter->displayLine('  ' . $note);
            }
        }

        $this->presenter->displaySuccess(sprintf(
            '%s %s moved back to %s',
            $this->boardService->checkIsTaskEntry($entry) ? 'Task' : 'Feature',
            $this->describeEntry($entry),
            $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_PROGRESS),
        ));
    }

    private function resolveByReference(BacklogBoard $board, string $reference, string $agent): BoardEntry
    {
        if (str_contains($reference, '/')) {
            $entry = $this->boardService->resolveTaskByReference($board, $reference, BacklogCommandName::REWORK->value)->getEntry();
        } else {
            $slug = $this->boardService->normalizeFeatureSlug($reference);

            $featureMatch = $this->boardService->findParentFeatureEntry($board, $slug);
            if ($featureMatch !== null && $featureMatch->getEntry()->getAgent() !== $agent) {
                $featureMatch = null;
            }

            $taskMatches = array_values(array_filter(
                $this->boardService->findTaskEntriesByTaskSlug($board, $slug),
                fn(mixed $match) => $match->getEntry()->getAgent() === $agent,
            ));

            if ($featureMatch !== null && $taskMatches !== []) {
                throw new RuntimeException(sprintf(
                    'Ambiguous reference %s: matches both a feature and a task. Use <feature/task> to disambiguate.',
                    $reference,
                ));
            }

            if ($featureMatch !== null) {
                return $featureMatch->getEntry();
            }

            if ($taskMatches !== []) {
                if (count($taskMatches) > 1) {
                    throw new RuntimeException(sprintf(
                        'rework requires <feature/task> because task slug %s is not unique.',
                        $slug,
                    ));
                }
                return $taskMatches[0]->getEntry();
            }

            throw new RuntimeException(sprintf('No active entry found for reference: %s', $reference));
        }

        if ($entry->getAgent() !== $agent) {
            throw new RuntimeException('rework requires the entry to be assigned to the provided agent.');
        }

        return $entry;
    }

    private function resolveRejectedEntryForAgent(BacklogBoard $board, string $agent): BoardEntry
    {
        $rejected = [];

        foreach ($this->boardService->findTaskEntriesByAgent($board, $agent) as $match) {
            if ($this->boardService->getFeatureStage($match->getEntry()) === BacklogBoard::STAGE_REJECTED) {
                $rejected[] = $match->getEntry();
            }
        }

        foreach ($this->boardService->findFeatureEntriesByAgent($board, $agent) as $match) {
            if ($this->boardService->getFeatureStage($match->getEntry()) === BacklogBoard::STAGE_REJECTED) {
                $rejected[] = $match->getEntry();
            }
        }

        if ($rejected === []) {
            throw new RuntimeException(sprintf('Agent %s has no rejected entry.', $agent));
        }

        if (count($rejected) > 1) {
            throw new RuntimeException(sprintf(
                'Agent %s has multiple rejected entries. Provide an explicit reference.',
                $agent,
            ));
        }

        return $rejected[0];
    }

    private function describeEntry(BoardEntry $entry): string
    {
        if ($this->boardService->checkIsTaskEntry($entry)) {
            return $this->boardService->getTaskReviewKey($entry);
        }

        return $entry->getFeature() ?? '-';
    }
}
