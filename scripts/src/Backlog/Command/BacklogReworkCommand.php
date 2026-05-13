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
 * Command for reworking a rejected or approved task or feature back to development.
 *
 * Used after a reviewer rejection (review notes are displayed) and after a merge
 * conflict on an approved entry, where the developer needs to resume coding.
 */
final class BacklogReworkCommand extends AbstractBacklogCommand
{
    /**
     * Stages from which an entry can be moved back to development.
     *
     * @var list<string>
     */
    private const REWORKABLE_STAGES = [
        BacklogBoard::STAGE_REJECTED,
        BacklogBoard::STAGE_APPROVED,
    ];

    private BacklogWorktreeService $worktreeService;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     */
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

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     * @return void
     */
    public function handle(array $commandArgs, array $options): void
    {
        $agent = $this->requireCallerAgent();

        $board = $this->loadBoard();
        $reference = $commandArgs[0] ?? null;

        if ($reference !== null) {
            $entry = $this->resolveByReference($board, $reference, $agent);
        } else {
            $entry = $this->resolveReworkableEntryForAgent($board, $agent);
        }

        $stage = $this->boardService->getFeatureStage($entry);
        if (!in_array($stage, self::REWORKABLE_STAGES, true)) {
            $expected = array_map(
                fn(string $candidate): string => $this->boardService->getStageLabel($candidate),
                self::REWORKABLE_STAGES,
            );
            throw new RuntimeException(sprintf(
                'Entry %s has stage %s, but rework only accepts %s.',
                $this->describeEntry($entry),
                $this->boardService->getStageLabel($stage),
                implode(' or ', $expected),
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
            '%s %s moved back to %s from %s',
            $this->boardService->checkIsTaskEntry($entry) ? 'Task' : 'Feature',
            $this->describeEntry($entry),
            $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_PROGRESS),
            $this->boardService->getStageLabel($stage),
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
                    'Ambiguous reference %s: matches both a feature and a task. Use a full <entry-ref> to disambiguate.',
                    $reference,
                ));
            }

            if ($featureMatch !== null) {
                return $featureMatch->getEntry();
            }

            if ($taskMatches !== []) {
                if (count($taskMatches) > 1) {
                    throw new RuntimeException(sprintf(
                        'rework requires a full <entry-ref> because task slug %s is not unique.',
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

    /**
     * Resolve the single reworkable entry (rejected or approved) assigned to the agent.
     */
    private function resolveReworkableEntryForAgent(BacklogBoard $board, string $agent): BoardEntry
    {
        $candidates = [];

        foreach ($this->boardService->findTaskEntriesByAgent($board, $agent) as $match) {
            if (in_array($this->boardService->getFeatureStage($match->getEntry()), self::REWORKABLE_STAGES, true)) {
                $candidates[] = $match->getEntry();
            }
        }

        foreach ($this->boardService->findFeatureEntriesByAgent($board, $agent) as $match) {
            if (in_array($this->boardService->getFeatureStage($match->getEntry()), self::REWORKABLE_STAGES, true)) {
                $candidates[] = $match->getEntry();
            }
        }

        if ($candidates === []) {
            throw new RuntimeException(sprintf(
                'Agent %s has no rejected or approved entry.',
                $agent,
            ));
        }

        if (count($candidates) > 1) {
            throw new RuntimeException(sprintf(
                'Agent %s has multiple reworkable entries. Provide an explicit reference.',
                $agent,
            ));
        }

        return $candidates[0];
    }

    private function describeEntry(BoardEntry $entry): string
    {
        if ($this->boardService->checkIsTaskEntry($entry)) {
            return $this->boardService->getTaskReviewKey($entry);
        }

        return $entry->getFeature() ?? '-';
    }
}
