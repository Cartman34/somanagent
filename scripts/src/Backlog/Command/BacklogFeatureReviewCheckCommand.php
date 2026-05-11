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

    /**
     * @param BacklogPresenter $presenter
     * @param bool $dryRun
     * @param string $projectRoot
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     * @param BacklogCommandFactory $commandFactory
     * @param FilesystemClientInterface $fs
     */
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

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     * @return void
     */
    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $feature = $this->resolveFeatureReferenceArgument($board, $commandArgs, BacklogCommandName::FEATURE_REVIEW_CHECK->value);
        $match = $this->boardService->resolveFeature($board, $feature);
        $entry = $match->getEntry();

        $stage = $this->boardService->getFeatureStage($entry);
        if ($stage !== BacklogBoard::STAGE_IN_REVIEW && $stage !== BacklogBoard::STAGE_REVIEWING) {
            throw new \RuntimeException(sprintf(
                'Feature %s must be in %s or %s to be checked.',
                $feature,
                $this->boardService->getStageLabel(BacklogBoard::STAGE_IN_REVIEW),
                $this->boardService->getStageLabel(BacklogBoard::STAGE_REVIEWING),
            ));
        }

        $reviewWorktree = $this->worktreeService->prepareFeatureAgentWorktree($entry);

        $savedResult = $this->worktreeService->loadReviewResult($reviewWorktree);

        if ($savedResult !== null) {
            echo rtrim($savedResult) . "\n";
            $this->presenter->displaySuccess(sprintf('Mechanical review passed for feature %s', $feature));

            return;
        }

        // Fallback: no saved result (feature submitted before this change was introduced)
        try {
            $this->worktreeService->runReviewScript($reviewWorktree, $entry->getBase());
        } catch (\RuntimeException $exception) {
            $message = 'Mechanical review `php scripts/review.php` failed. Fix mechanical issues before submitting the feature again.';

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
