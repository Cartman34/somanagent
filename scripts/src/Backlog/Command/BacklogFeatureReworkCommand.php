<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;

/**
 * Command for reworking a rejected feature.
 */
final class BacklogFeatureReworkCommand extends AbstractBacklogCommand
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
            throw new \RuntimeException('Option --agent is required.');
        }
        $board = $this->loadBoard();
        $match = isset($commandArgs[0])
            ? $this->boardService->resolveFeature($board, $this->boardService->normalizeFeatureSlug($commandArgs[0]))
            : $this->boardService->resolveSingleFeatureForAgent($board, $agent);
        $entry = $match->getEntry();
        $this->boardService->checkIsFeatureEntry($entry) || throw new \RuntimeException('feature-rework only applies to kind=feature entries.');

        if ($entry->getAgent() !== $agent) {
            throw new \RuntimeException('feature-rework requires the feature to be assigned to the provided agent.');
        }

        if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_REJECTED) {
            throw new \RuntimeException(sprintf(
                'Feature %s is not in the rejected stage.',
                $entry->getFeature() ?? '-',
            ));
        }

        $entry->setStage(BacklogBoard::STAGE_IN_PROGRESS);
        $this->saveBoard($board, BacklogCommandName::FEATURE_REWORK->value);

        $worktree = $this->worktreeService->prepareAgentWorktree($agent);
        $this->worktreeService->checkoutBranchInWorktree($worktree, $entry->getBranch() ?? '', false);

        $this->presenter->displaySuccess(sprintf(
            'Feature %s moved back to %s',
            $entry->getFeature() ?? '-',
            $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_PROGRESS),
        ));
    }
}
