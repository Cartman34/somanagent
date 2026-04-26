<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogBoard;
use SoManAgent\Script\Backlog\BacklogCommandFactory;
use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\BacklogPresenter;

/**
 * Command for checking a feature review.
 */
final class BacklogFeatureReviewCheckCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogWorktreeManager $worktreeManager;

    private BacklogCommandFactory $commandFactory;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogWorktreeManager $worktreeManager,
        BacklogCommandFactory $commandFactory
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
        $this->worktreeManager = $worktreeManager;
        $this->commandFactory = $commandFactory;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $feature = $this->entryResolver->requireFeatureByReferenceArgument($board, $commandArgs, BacklogCommandName::FEATURE_REVIEW_CHECK->value);
        $match = $this->entryResolver->requireFeature($board, $feature);
        $entry = $match->getEntry();

        if ($this->entryService->featureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Feature %s must be in %s to be checked.',
                $feature,
                BacklogBoard::stageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $reviewWorktree = $this->worktreeManager->prepareFeatureAgentWorktree($entry);

        try {
            $this->worktreeManager->runReviewScript($reviewWorktree, $entry->getBase());
        } catch (\RuntimeException $exception) {
            $message = 'Mechanical review `php scripts/review.php` failed. Fix mechanical issues before submitting the feature again.';
            
            // Delegate to reject command
            $tempBodyFile = tempnam(sys_get_temp_dir(), 'somanagent-review-');
            file_put_contents($tempBodyFile, $message);
            
            $this->commandFactory->createHandler(BacklogCommandName::FEATURE_REVIEW_REJECT->value)->handle(
                [$feature],
                ['body-file' => $tempBodyFile]
            );
            
            unlink($tempBodyFile);
            
            throw $exception;
        }

        $this->presenter->displaySuccess(sprintf('Mechanical review passed for feature %s', $feature));
    }
}
