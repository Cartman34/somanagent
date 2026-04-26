<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Client\ConsoleClient;

/**
 * Command for listing managed and external worktrees.
 */
final class BacklogWorktreeListCommand extends AbstractBacklogCommand
{
    private BacklogWorktreeManager $worktreeManager;

    private ConsoleClient $consoleClient;

    public function __construct(BacklogCommandContext $context)
    {
        parent::__construct($context);
        $this->worktreeManager = $context->getWorktreeManager();
        $this->consoleClient = $context->getConsoleClient();
    }

    public function handle(array $commandArgs, array $options): void
    {
        $board = $this->loadBoard();
        $classification = $this->worktreeManager->classifyWorktrees($board);

        if ($classification->getManaged() === [] && $classification->getExternal() === []) {
            $this->console->line('No worktree to report.');

            return;
        }

        if ($classification->getManaged() !== []) {
            $this->console->line('[Managed worktrees]');
            foreach ($classification->getManaged() as $item) {
                $parts = [
                    $this->consoleClient->toRelativeProjectPath($item->getPath()),
                    'state=' . $item->getState()->value,
                    'branch=' . ($item->getBranch() ?? '-'),
                    'feature=' . ($item->getFeature() ?? '-'),
                    'agent=' . ($item->getAgent() ?? '-'),
                    'action=' . $item->getAction()->value,
                ];
                $this->console->line('- ' . implode(' ', $parts));
            }
        }

        if ($classification->getExternal() !== []) {
            $this->console->line('[External worktrees]');
            foreach ($classification->getExternal() as $item) {
                $parts = [
                    $item->getPath(),
                    'branch=' . ($item->getBranch() ?? '-'),
                    'action=' . $item->getAction()->value,
                ];
                $this->console->line('- ' . implode(' ', $parts));
            }
            $this->console->line('Manual cleanup: verify each external worktree is disposable, then use `git worktree remove <path>` or `git worktree prune` when only metadata remains.');
        }
    }
}
