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
use SoManAgent\Script\Backlog\PullRequestService;

/**
 * Command for approving a feature review.
 */
final class BacklogFeatureReviewApproveCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogGitWorkflow $gitWorkflow;

    private PullRequestService $pullRequestService;

    public function __construct(BacklogCommandContext $context)
    {
        parent::__construct($context);
        $this->entryResolver = $context->getEntryResolver();
        $this->entryService = $context->getEntryService();
        $this->gitWorkflow = $context->getGitWorkflow();
        $this->pullRequestService = $context->getPullRequestService();
    }

    public function handle(array $commandArgs, array $options): void
    {
        $bodyFile = $options['body-file'] ?? null;
        if (!is_string($bodyFile)) {
            throw new \RuntimeException('Option --body-file is required.');
        }

        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $feature = $this->entryResolver->requireFeatureByReferenceArgument($board, $commandArgs, BacklogCommandName::FEATURE_REVIEW_APPROVE->value);
        $match = $this->entryResolver->requireFeature($board, $feature);
        $entry = $match->getEntry();

        if ($this->entryService->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Feature %s must be in %s to be approved.',
                $feature,
                BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $branch = $entry->getBranch();
        if ($branch === null) {
            throw new \RuntimeException('Feature has no branch metadata.');
        }

        $type = $this->pullRequestService->determinePrType($entry, $this->gitWorkflow);
        $title = $this->pullRequestService->buildPrTitle($type, $entry);

        $this->pullRequestService->pushBranchAndWaitForRemoteVisibility($branch);
        $this->pullRequestService->createOrUpdatePr($branch, $title, $bodyFile, $this->prBaseBranch($options));
        $prNumber = $this->pullRequestService->findPrNumberByBranch($branch);
        if ($prNumber !== null) {
            $entry->setPr((string) $prNumber);
        }

        $entry->setStage(BacklogBoard::STAGE_APPROVED);
        $review->clearReview($feature);
        $this->saveBoard($board, BacklogCommandName::FEATURE_REVIEW_APPROVE->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_REVIEW_APPROVE->value);

        $this->console->ok(sprintf('Approved feature %s with [%s] PR title', $feature, $type));
    }

    private function prBaseBranch(array $options): string
    {
        return is_string($options['pr-base-branch'] ?? null) ? $options['pr-base-branch'] : BacklogGitWorkflow::MAIN_BRANCH;
    }
}
