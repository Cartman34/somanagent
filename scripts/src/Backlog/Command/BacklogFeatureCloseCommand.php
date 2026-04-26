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
use SoManAgent\Script\Backlog\BacklogGitWorkflow;

/**
 * Command for closing an active feature.
 */
final class BacklogFeatureCloseCommand extends AbstractBacklogCommand
{
    private BacklogEntryResolver $entryResolver;

    private BacklogEntryService $entryService;

    private BacklogWorktreeManager $worktreeManager;

    private BacklogGitWorkflow $gitWorkflow;

    public function __construct(BacklogCommandContext $context)
    {
        parent::__construct($context);
        $this->entryResolver = $context->getEntryResolver();
        $this->entryService = $context->getEntryService();
        $this->worktreeManager = $context->getWorktreeManager();
        $this->gitWorkflow = $context->getGitWorkflow();
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
