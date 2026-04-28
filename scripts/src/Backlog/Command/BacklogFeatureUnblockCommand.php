<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Service\GitService;
use SoManAgent\Script\Service\PullRequestService;

/**
 * Command for unblocking a feature.
 */
final class BacklogFeatureUnblockCommand extends AbstractBacklogCommand
{
    private GitService $gitService;

    private PullRequestService $pullRequestService;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        GitService $gitService,
        PullRequestService $pullRequestService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->gitService = $gitService;
        $this->pullRequestService = $pullRequestService;
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
        $feature = $entry->getFeature() ?? '-';
        if ($entry->getAgent() !== $agent) {
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
