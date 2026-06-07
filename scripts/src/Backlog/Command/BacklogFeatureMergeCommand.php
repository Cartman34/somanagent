<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Command;

use Sowapps\SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use Sowapps\Toolkit\Service\GitService;
use Sowapps\Toolkit\Service\PullRequestService;
use Sowapps\SoManAgent\Script\Backlog\Service\BodyFilePathResolver;
use Sowapps\SoManAgent\Script\Backlog\Service\PostMergeSessionStopper;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogPresenter;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use Sowapps\SoManAgent\Script\Backlog\Model\BacklogBoard;
use Sowapps\SoManAgent\Script\Backlog\Enum\BacklogEntryMetaKey;

/**
 * Command for merging a feature into the base branch.
 */
final class BacklogFeatureMergeCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    private GitService $gitService;

    private PullRequestService $pullRequestService;

    private BodyFilePathResolver $bodyFilePathResolver;

    private PostMergeSessionStopper $sessionStopper;

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     * @param GitService $gitService
     * @param PullRequestService $pullRequestService
     * @param BodyFilePathResolver $bodyFilePathResolver
     * @param PostMergeSessionStopper $sessionStopper
     */
    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        GitService $gitService,
        PullRequestService $pullRequestService,
        BodyFilePathResolver $bodyFilePathResolver,
        PostMergeSessionStopper $sessionStopper
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
        $this->gitService = $gitService;
        $this->pullRequestService = $pullRequestService;
        $this->bodyFilePathResolver = $bodyFilePathResolver;
        $this->sessionStopper = $sessionStopper;
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     * @return void
     */
    public function handle(array $commandArgs, array $options): void
    {
        throw new \RuntimeException(
            'feature-merge is no longer a public command. Use: php scripts/backlog.php merge <entry-ref> --agent=<reviewer>',
        );
    }

    /**
     * Performs the feature merge in a fixed, retry-safe sequence:
     *   1. mergePr          — idempotent: no-op when PR already merged
     *   2. syncMain         — pull main after merge
     *   3. removeWorktreeForBranch — removes the worktree checked out on the feature branch; board-state independent
     *   4. deleteRemoteBranch
     *   5. deleteLocalBranch
     *   6. installProjectDependencies — optional, skipped when meta absent
     *   7. deleteFeature + clearReview + saveBoard + saveReviewFile — board last so a crash
     *      before this point leaves the entry in approved and the command can be retried
     *   8. stopSessions
     *
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     * @return void
     */
    public function performMerge(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $review = $this->loadReviewFile();
        $bodyFile = null;
        if (array_key_exists(BacklogCliOption::BODY_FILE->value, $options)) {
            $bodyFileOption = $options[BacklogCliOption::BODY_FILE->value];
            if (!is_string($bodyFileOption) || trim($bodyFileOption) === '') {
                throw new \RuntimeException('Option --body-file requires a non-empty path when provided.');
            }
            $bodyFile = $bodyFileOption;
        }

        $feature = $this->resolveFeatureReferenceArgument($board, $commandArgs, BacklogCommandName::FEATURE_MERGE->value);

        $match = $this->boardService->resolveFeature($board, $feature);
        $entry = $match->getEntry();
        $this->boardService->checkIsFeatureEntry($entry) || throw new \RuntimeException('feature-merge only applies to kind=feature entries.');
        $this->boardService->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_MERGE->value);

        $devCode = $entry->getDeveloper();
        $reviewerCode = $entry->getReviewer();

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

        $prNumberRaw = $entry->getPr();
        $prNumber = $prNumberRaw !== null ? (int) $prNumberRaw : null;
        if ($prNumber === null) {
            throw new \RuntimeException("No PR found for feature {$feature}. Create a pull request before merging.");
        }

        if ($bodyFile !== null) {
            $tag = $this->pullRequestService->getPrTypeFromChanges($entry->getBase() ?? '', $branch);
            $title = $this->pullRequestService->buildPrTitle($tag, $entry->getText());
            $this->pullRequestService->createOrUpdatePr($branch, $title, $this->bodyFilePathResolver->resolveForEntry($bodyFile, $feature));
        }

        $dependencyUpdate = $entry->getExtraMetadata()[BacklogEntryMetaKey::DEPENDENCY_UPDATE->value] ?? '';

        // GitHub merge — idempotent: no-op when PR is already merged.
        $this->pullRequestService->mergePr($prNumber);
        $this->gitService->syncMainBranchAfterMerge();

        // Local cleanup — all idempotent, before board persistence so a retry can resume here.
        $this->worktreeService->removeWorktreeForBranch($branch);
        $this->gitService->deleteRemoteBranch($branch);
        $this->gitService->deleteLocalBranch($branch);

        $warnings = [];
        if ($dependencyUpdate !== '') {
            $warnings = $this->worktreeService->installProjectDependencies($dependencyUpdate);
        }

        // Board and review persistence — last mutations so a crash before this point leaves
        // the entry in approved stage and the command can be retried safely.
        $this->boardService->deleteFeature($board, $feature);
        $review->clearReview($feature);
        $this->saveBoard($board, BacklogCommandName::FEATURE_MERGE->value);
        $this->saveReviewFile($review, BacklogCommandName::FEATURE_MERGE->value);

        $this->presenter->displaySuccess(sprintf('Merged feature %s', $feature));

        foreach ($warnings as $warning) {
            $this->presenter->displayLine('⚠ ' . $warning);
        }

        $this->sessionStopper->stopSessions($devCode, $reviewerCode);
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
