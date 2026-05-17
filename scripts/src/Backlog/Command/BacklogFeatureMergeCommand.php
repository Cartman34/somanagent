<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Backlog\Service\BodyFilePathResolver;
use SoManAgent\Script\Service\GitService;
use SoManAgent\Script\Service\PullRequestService;

/**
 * Command for merging a feature into the base branch.
 */
final class BacklogFeatureMergeCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    private GitService $gitService;

    private PullRequestService $pullRequestService;

    private BodyFilePathResolver $bodyFilePathResolver;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     * @param GitService $gitService
     * @param PullRequestService $pullRequestService
     * @param BodyFilePathResolver $bodyFilePathResolver
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        GitService $gitService,
        PullRequestService $pullRequestService,
        BodyFilePathResolver $bodyFilePathResolver
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
        $this->gitService = $gitService;
        $this->pullRequestService = $pullRequestService;
        $this->bodyFilePathResolver = $bodyFilePathResolver;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     * @return void
     */
    public function handle(array $commandArgs, array $options): void
    {
        throw new \RuntimeException(
            'feature-merge is no longer a public command. Use: php scripts/backlog.php entry-merge <entry-ref> --agent=<reviewer>',
        );
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     * @return void
     */
    public function performMerge(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $bodyFile = null;
        if (array_key_exists('body-file', $options)) {
            $bodyFileOption = $options['body-file'];
            if (!is_string($bodyFileOption) || trim($bodyFileOption) === '') {
                throw new \RuntimeException('Option --body-file requires a non-empty path when provided.');
            }
            $bodyFile = $bodyFileOption;
        }

        $feature = $this->resolveFeatureReferenceArgument($board, $commandArgs, 'feature-merge');

        $match = $this->boardService->resolveFeature($board, $feature);
        $entry = $match->getEntry();
        $this->boardService->checkIsFeatureEntry($entry) || throw new \RuntimeException('feature-merge only applies to kind=feature entries.');
        $this->boardService->assertNoActiveTasksForFeature($board, $feature, 'feature-merge');

        if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_APPROVED) {
            throw new \RuntimeException(sprintf(
                'Feature %s must be in %s to be merged.',
                $feature,
                $this->boardService->getStageLabel(BacklogBoard::STAGE_APPROVED),
            ));
        }
        if ($entry->checkIsBlocked()) {
            throw new \RuntimeException("Feature {$feature} is blocked and cannot be merged.");
        }

        $branch = $entry->getBranch() ?? '';
        if ($branch === '') {
            throw new \RuntimeException("Feature {$feature} has no branch metadata.");
        }

        $prNumber = $this->pullRequestService->findPrNumberByBranch($branch);
        if ($prNumber === null) {
            throw new \RuntimeException("No open PR found for branch {$branch}.");
        }

        if ($bodyFile !== null) {
            $tag = $this->pullRequestService->getPrTypeFromChanges($entry->getBase() ?? '', $branch);
            $title = $this->pullRequestService->buildPrTitle($tag, $entry->getText());
            $this->pullRequestService->createOrUpdatePr($branch, $title, $this->bodyFilePathResolver->resolveForEntry($bodyFile, $feature));
        }
        $this->pullRequestService->mergePr($prNumber);
        $this->gitService->syncMainBranchAfterMerge();

        $this->boardService->deleteFeature($board, $feature);
        $review->clearReview($feature);
        $this->saveBoard($board, 'feature-merge');
        $this->saveReviewFile($review, 'feature-merge');

        $cleaned = $this->worktreeService->cleanupManagedWorktreesForBranch($branch, $board);
        $this->gitService->deleteRemoteBranch($branch);
        $this->gitService->deleteLocalBranch($branch);

        $this->presenter->displaySuccess(sprintf('Merged feature %s', $feature));
        if ($cleaned > 0) {
            $this->presenter->displayLine(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }
    }

    /**
     * @param array<string> $commandArgs
     */
    private function resolveFeatureReferenceArgument(BacklogBoard $board, array $commandArgs, string $command): string
    {
        if (!isset($commandArgs[0]) || trim($commandArgs[0]) === '') {
            throw new \RuntimeException(sprintf('%s requires <feature>.', $command));
        }

        return $this->boardService->normalizeFeatureSlug($commandArgs[0]);
    }
}
