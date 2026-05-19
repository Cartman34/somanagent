<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Service\PullRequestService;

/**
 * Command for unblocking a feature.
 */
final class BacklogFeatureUnblockCommand extends AbstractBacklogCommand
{
    private PullRequestService $pullRequestService;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param PullRequestService $pullRequestService
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        PullRequestService $pullRequestService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->pullRequestService = $pullRequestService;
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
        $match = isset($commandArgs[0])
            ? $this->boardService->resolveFeature($board, $this->boardService->normalizeFeatureSlug($commandArgs[0]))
            : $this->boardService->resolveSingleFeatureForAgent($board, $agent);
        
        $entry = $match->getEntry();
        $feature = $entry->getFeature() ?? '-';
        if ($entry->getDeveloper() !== $agent) {
            throw new \RuntimeException("Feature {$feature} is not assigned to agent {$agent}.");
        }

        $entry->setBlocked(false);
        $this->saveBoard($board, BacklogCommandName::FEATURE_UNBLOCK->value);

        $prNumber = $this->pullRequestService->findPrNumberByBranch($entry->getBranch() ?? '');
        if ($prNumber !== null) {
            $tag = $this->pullRequestService->getPrTypeFromChanges($entry->getBase() ?? '', $entry->getBranch() ?? '');
            $title = $this->pullRequestService->buildPrTitle($tag, $entry->getText(), false);
            $this->pullRequestService->editPrTitle($prNumber, $title);
        }

        $this->presenter->displaySuccess(sprintf('Removed blocked flag from feature %s', $feature));
    }
}
