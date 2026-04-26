<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\BoardEntry;

/**
 * Command for restoring an agent worktree.
 */
final class BacklogWorktreeRestoreCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeManager $worktreeManager;

    private BacklogEntryResolver $entryResolver;

    public function __construct(BacklogCommandContext $context)
    {
        parent::__construct($context);
        $this->worktreeManager = $context->getWorktreeManager();
        $this->entryResolver = $context->getEntryResolver();
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $agent = BoardEntry::parseEmptyString((string) ($options['agent'] ?? ''));
        if ($agent === null) {
            throw new \RuntimeException('worktree-restore requires --agent=<code>.');
        }

        $taskEntry = $this->entryResolver->getSingleTaskForAgent($board, $agent, false);
        $featureEntry = $this->entryResolver->getSingleFeatureForAgent($board, $agent, false);

        if ($taskEntry === null && $featureEntry === null) {
            throw new \RuntimeException("Agent {$agent} has no active task or feature.");
        }

        $entry = $taskEntry ?? $featureEntry;
        $branch = $entry->getBranch();
        if ($branch === null) {
            throw new \RuntimeException("Agent {$agent} has an active entry but no branch metadata.");
        }

        $worktree = $this->worktreeManager->prepareAgentWorktree($agent);
        $this->worktreeManager->checkoutBranchInWorktree($worktree, $branch, false);

        $this->console->ok(sprintf('Restored worktree for agent %s on branch %s', $agent, $branch));
    }
}
