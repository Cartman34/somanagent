<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\SoManAgent\Script\Service\PullRequestService;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCommandName;

/**
 * Command for unblocking a feature.
 */
final class BacklogFeatureUnblockCommand extends AbstractBacklogCommand
{
    private const ROLE_MANAGER = 'manager';

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
        $isManager = $this->readCallerRole() === self::ROLE_MANAGER;
        $requestedFeature = $this->boardService->sanitizeString($commandArgs[0] ?? null);
        if ($isManager && $requestedFeature === null) {
            throw new \RuntimeException('feature-unblock requires an explicit <feature> when SOMANAGER_ROLE=manager.');
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
