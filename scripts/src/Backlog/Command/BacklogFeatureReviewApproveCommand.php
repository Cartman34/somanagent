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
use SoManAgent\Script\Backlog\BacklogMetaValue;
use SoManAgent\Script\Backlog\BoardEntry;
use SoManAgent\Script\Backlog\PullRequestManager;
use SoManAgent\Script\Backlog\PullRequestTag;
use SoManAgent\Script\Console;

/**
 * Command for approving a feature review.
 */
final class BacklogFeatureReviewApproveCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogGitWorkflow $gitWorkflow;

    private PullRequestManager $pullRequestManager;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogGitWorkflow $gitWorkflow,
        PullRequestManager $pullRequestManager
    ) {
        parent::__construct($console, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
        $this->gitWorkflow = $gitWorkflow;
        $this->pullRequestManager = $pullRequestManager;
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

        $type = $this->determinePrType($entry);
        $title = $this->buildPrTitle($type, $entry);

        $this->pullRequestManager->pushBranchAndWaitForRemoteVisibility($branch);
        $this->pullRequestManager->createOrUpdatePr($branch, $title, $bodyFile, $this->prBaseBranch($options));
        $prNumber = $this->pullRequestManager->findPrNumberByBranch($branch);
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

    private function determinePrType(BoardEntry $entry): string
    {
        $base = $entry->getBase();
        $branch = $entry->getBranch();
        if ($base === null || $branch === null) {
            throw new \RuntimeException('Cannot determine PR type without base and branch metadata.');
        }

        $files = $this->gitWorkflow->changedFiles($base, $branch);

        if ($files === []) {
            return str_starts_with($branch, 'fix/') ? PullRequestTag::FIX->value : PullRequestTag::FEAT->value;
        }

        $docOnly = true;
        $techOnly = true;

        foreach ($files as $file) {
            if (!str_starts_with($file, 'doc/') && $file !== 'AGENTS.md') {
                $docOnly = false;
            }

            if (
                !str_starts_with($file, 'scripts/')
                && !str_starts_with($file, '.github/')
                && !in_array($file, ['AGENTS.md', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'pnpm-lock.yaml'], true)
            ) {
                $techOnly = false;
            }
        }

        if ($docOnly) {
            return PullRequestTag::DOC->value;
        }

        if ($techOnly) {
            return PullRequestTag::TECH->value;
        }

        return str_starts_with($branch, 'fix/') ? PullRequestTag::FIX->value : PullRequestTag::FEAT->value;
    }

    private function buildPrTitle(string $type, BoardEntry $entry): string
    {
        $title = sprintf('[%s] %s', $type, $entry->getText());

        return $entry->isBlocked()
            ? $this->ensureBlockedTitle($title)
            : $title;
    }

    private function ensureBlockedTitle(string $title): string
    {
        $tag = '[' . PullRequestTag::BLOCKED->value . ']';

        return str_contains($title, $tag)
            ? $title
            : $tag . ' ' . $title;
    }
}
