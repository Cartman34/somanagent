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
use SoManAgent\Script\Console;

/**
 * Command for closing an active feature.
 */
final class BacklogFeatureCloseCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogWorktreeManager $worktreeManager;

    private BacklogGitWorkflow $gitWorkflow;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogEntryResolver $entryResolver,
        BacklogEntryService $entryService,
        BacklogWorktreeManager $worktreeManager,
        BacklogGitWorkflow $gitWorkflow
    ) {
        parent::__construct($console, $dryRun, $projectRoot);
        $this->entryResolver = $entryResolver;
        $this->entryService = $entryService;
        $this->worktreeManager = $worktreeManager;
        $this->gitWorkflow = $gitWorkflow;
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $feature = $this->entryResolver->requireFeatureByReferenceArgument($board, $commandArgs, BacklogCommandName::FEATURE_CLOSE->value);
        $match = $this->entryResolver->requireFeature($board, $feature);
        $entry = $match->getEntry();
        $branch = $entry->getBranch();

        $this->entryService->assertFeatureEntry($entry, BacklogCommandName::FEATURE_CLOSE->value);
        $this->entryResolver->assertNoActiveTasksForFeature($board, $feature, BacklogCommandName::FEATURE_CLOSE->value);

        $board->removeFeature($feature);
        $this->saveBoard($board, BacklogCommandName::FEATURE_CLOSE->value);

        $cleaned = 0;
        if ($branch !== null) {
            $cleaned = $this->worktreeManager->cleanupManagedWorktreesForBranch($branch, $board);
            $this->gitWorkflow->deleteLocalBranchIfExists($branch);
        }

        $this->console->ok(sprintf('Closed feature %s', $feature));
        if ($cleaned > 0) {
            $this->console->line(sprintf('Cleaned %d abandoned managed worktree%s.', $cleaned, $cleaned > 1 ? 's' : ''));
        }
    }
}
