<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogCommandFactory;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogPresenter;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Client\FilesystemClientInterface;

/**
 * Command for checking a feature review.
 */
final class BacklogFeatureReviewCheckCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeService $worktreeService;

    private BacklogCommandFactory $commandFactory;

    private FilesystemClientInterface $fs;

    public function __construct(
        BacklogPresenter $presenter,
        bool $dryRun,
        string $projectRoot,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        BacklogCommandFactory $commandFactory,
        FilesystemClientInterface $fs
    ) {
        parent::__construct($presenter, $dryRun, $projectRoot, $boardService);
        $this->worktreeService = $worktreeService;
        $this->commandFactory = $commandFactory;
        $this->fs = $fs;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $feature = $this->resolveFeatureReferenceArgument($board, $commandArgs, BacklogCommandName::FEATURE_REVIEW_CHECK->value);
        $match = $this->boardService->resolveFeature($board, $feature);
        $entry = $match->getEntry();

        if ($this->boardService->getFeatureStage($entry) !== BacklogBoard::STAGE_IN_REVIEW) {
            throw new \RuntimeException(sprintf(
                'Feature %s must be in %s to be checked.',
                $feature,
                $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
            ));
        }

        $reviewWorktree = $this->worktreeService->prepareFeatureAgentWorktree($entry);

        try {
            $this->worktreeService->runReviewScript($reviewWorktree, $entry->getBase());
        } catch (\RuntimeException $exception) {
            $message = 'Mechanical review `php scripts/review.php` failed. Fix mechanical issues before submitting the feature again.';

            // Delegate to reject command
            $tempBodyFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'somanagent-review-' . bin2hex(random_bytes(8));
            $this->fs->writeFilePath($tempBodyFile, $message);

            $this->commandFactory->createHandler(BacklogCommandName::FEATURE_REVIEW_REJECT->value)->handle(
                [$feature],
                ['body-file' => $tempBodyFile]
            );

            $this->fs->removePath($tempBodyFile);

            throw $exception;
        }

        $this->presenter->displaySuccess(sprintf('Mechanical review passed for feature %s', $feature));
    }

    private function resolveFeatureReferenceArgument(BacklogBoard $board, array $commandArgs, string $command): string
    {
        if (!isset($commandArgs[0]) || trim($commandArgs[0]) === '') {
            throw new \RuntimeException(sprintf('%s requires <feature>.', $command));
        }

        return $this->boardService->normalizeFeatureSlug($commandArgs[0]);
    }
}
