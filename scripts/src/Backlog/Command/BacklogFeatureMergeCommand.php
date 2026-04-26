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
use SoManAgent\Script\Backlog\BacklogGitWorkflow;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\Backlog\PullRequestService;
use SoManAgent\Script\Backlog\BacklogPresenter;

/**
 * Command for merging a feature into the base branch.
 */
final class BacklogFeatureMergeCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogWorktreeManager $worktreeManager;

    private BacklogGitWorkflow $gitWorkflow;

    private PullRequestService $pullRequestService;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogWorktreeManager $worktreeManager,
        BacklogGitWorkflow $gitWorkflow,
        PullRequestService $pullRequestService
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
        $this->worktreeManager = $worktreeManager;
        $this->gitWorkflow = $gitWorkflow;
        $this->pullRequestService = $pullRequestService;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $feature = $this->entryResolver->requireFeatureByReferenceArgument($board, $commandArgs, BacklogCommandName::FEATURE_MERGE->value);

        $match = $this->entryResolver->requireFeature($board, $feature);
        $entry = $match->getEntry();
        $this->entryService->assertFeatureEntry($entry, BacklogCommandName::FEATURE_MERGE->value);
        $this->entryResolver->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_MERGE->value);

        if ($this->entryService->featureStage($entry) !== BacklogBoard::STAGE_APPROVED) {
            throw new \RuntimeException(sprintf(
                'Feature %s must be in %s to be merged.',
                $feature,
                BacklogBoard::stageLabel(BacklogBoard::STAGE_APPROVED),
            ));
        }

        $branch = $entry->getBranch() ?? '';
        $prNumber = $this->storedPrNumber($entry);

        if ($prNumber !== null) {
            $this->pullRequestService->mergePr($prNumber);
        } else {
            $this->gitWorkflow->mergeBranchInPath($this->projectRoot, $branch, "Merge feature {$feature}");
        }

        $board->removeFeature($feature);
        $review->clearReview($feature);
        $this->saveBoard($board, BacklogCommandName::FEATURE_MERGE->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_MERGE->value);

        $cleaned = $this->worktreeManager->cleanupManagedWorktreesForBranch($branch, $board);
        $this->gitWorkflow->deleteRemoteBranch($branch);
        $this->gitWorkflow->deleteLocalBranchIfExists($branch);

        $this->presenter->displaySuccess(sprintf('Merged feature %s', $feature));
        if ($cleaned > 0) {
            $this->presenter->displayLine(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }
    }
}
