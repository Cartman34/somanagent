<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\Toolkit\Service\PullRequestService;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\Toolkit\Enum\PullRequestTag;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentRole;

/**
 * Command for blocking a feature.
 */
final class BacklogFeatureBlockCommand extends AbstractBacklogCommand
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
     * Block a feature in the backlog board.
     *
     * @param list<string> $commandArgs Feature slug (optional, auto-detect if not provided)
     * @param array<string, bool|string> $options Command options
     * @return void
     */
    public function handle(array $commandArgs, array $options): void
    {
        $agent = $this->requireCallerAgent();
        $isManager = $this->readCallerRole() === AgentRole::MANAGER->value;
        $requestedFeature = $this->boardService->sanitizeString($commandArgs[0] ?? null);
        if ($isManager && $requestedFeature === null) {
            throw new \RuntimeException('feature-block requires an explicit <feature> when SOMANAGER_ROLE=manager.');
        }

        $board = $this->loadBoard();
        $match = $requestedFeature !== null
            ? $this->boardService->resolveFeature($board, $this->boardService->normalizeFeatureSlug($requestedFeature))
            : $this->boardService->resolveSingleFeatureForAgent($board, $agent);

        $entry = $match->getEntry();
        $feature = $entry->getFeature() ?? '-';
        if (!$isManager && $entry->getDeveloper() !== $agent) {
            throw new \RuntimeException("Feature {$feature} is not assigned to developer {$agent}.");
        }

        $entry->setBlocked(true);
        $this->saveBoard($board, BacklogCommandName::FEATURE_BLOCK->value);

        $prNumber = $this->pullRequestService->findPrNumberByBranch($entry->getBranch() ?? '');
        if ($prNumber !== null) {
            $tag = $this->boardService->getFeatureStage($entry) === BacklogBoard::STAGE_APPROVED
                ? $this->pullRequestService->getPrTypeFromChanges($entry->getBase() ?? '', $entry->getBranch() ?? '')
                : PullRequestTag::WIP;
            $title = $this->pullRequestService->buildPrTitle($tag, $entry->getText(), true);
            $this->pullRequestService->editPrTitle($prNumber, $title);
        }

        $this->presenter->displaySuccess(sprintf('Marked feature %s as blocked', $feature));
    }
}
