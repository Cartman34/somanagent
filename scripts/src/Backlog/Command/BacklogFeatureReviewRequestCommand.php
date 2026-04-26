<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\BacklogPresenter;

/**
 * Command for requesting a review for a feature.
 */
final class BacklogFeatureReviewRequestCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogWorktreeManager $worktreeManager;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogWorktreeManager $worktreeManager
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
        $this->worktreeManager = $worktreeManager;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $agent = $options['agent'] ?? null;
        if (!is_string($agent)) {
            throw new \RuntimeException('Option --agent is required.');
        }
        $board = $this->loadBoard();
        $feature = isset($commandArgs[0])
            ? $this->entryService->normalizeFeatureSlug($commandArgs[0])
            : $this->entryResolver->requireSingleFeatureForAgent($board, $agent)->getEntry()->getFeature();

        if ($feature === null) {
            throw new \RuntimeException('No feature available for feature-review-request.');
        }

        $match = $this->entryResolver->requireFeature($board, $feature);
        $entry = $match->getEntry();
        $this->entryService->assertFeatureEntry($entry, BacklogCommandName::FEATURE_REVIEW_REQUEST->value);
        $this->entryResolver->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_REVIEW_REQUEST->value);
        
        if ($this->entryService->featureStage($entry) !== BacklogBoard::STAGE_IN_PROGRESS) {
            throw new \RuntimeException("Feature {$feature} must be in " . BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_PROGRESS) . '.');
        }
        if ($entry->getAgent() !== $agent) {
            throw new \RuntimeException("Feature {$feature} is not assigned to agent {$agent}.");
        }

        $worktree = $this->worktreeManager->prepareFeatureAgentWorktree($entry);
        $this->worktreeManager->runReviewScript($worktree, $entry->getBase());

        $entry->setStage(BacklogBoard::STAGE_IN_REVIEW);
        $this->saveBoard($board, BacklogCommandName::FEATURE_REVIEW_REQUEST->value);

        $this->presenter->displaySuccess(sprintf('Feature %s moved to %s', $feature, BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW)));
    }
}
