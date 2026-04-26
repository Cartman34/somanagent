<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

use SoManAgent\Script\Backlog\Handler\AbstractBacklogHandler;
use SoManAgent\Script\Backlog\Handler\StatusHandler;
use SoManAgent\Script\Backlog\Handler\WorktreeListHandler;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\TextSlugger;

/**
 * Factory for creating backlog command handlers with their dependencies.
 */
final class BacklogHandlerFactory
{
    private Console $console;

    private bool $dryRun;

    private string $projectRoot;

    private BacklogWorktreeManager $worktreeManager;

    private BacklogEntryService $entryService;

    private BacklogEntryResolver $entryResolver;

    private ConsoleClient $consoleClient;

    private TextSlugger $featureSlugger;

    private string $boardPath;

    private string $reviewFilePath;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogWorktreeManager $worktreeManager,
        BacklogEntryService $entryService,
        BacklogEntryResolver $entryResolver,
        ConsoleClient $consoleClient,
        TextSlugger $featureSlugger,
        string $boardPath,
        string $reviewFilePath
    ) {
        $this->console = $console;
        $this->dryRun = $dryRun;
        $this->projectRoot = $projectRoot;
        $this->worktreeManager = $worktreeManager;
        $this->entryService = $entryService;
        $this->entryResolver = $entryResolver;
        $this->consoleClient = $consoleClient;
        $this->featureSlugger = $featureSlugger;
        $this->boardPath = $boardPath;
        $this->reviewFilePath = $reviewFilePath;
    }

    public function createHandler(string $commandName): AbstractBacklogHandler
    {
        $handler = match ($commandName) {
            BacklogCommandName::STATUS->value => new StatusHandler(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->entryResolver,
                $this->entryService,
                $this->worktreeManager,
                $this->consoleClient
            ),
            BacklogCommandName::WORKTREE_LIST->value => new WorktreeListHandler(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->worktreeManager,
                $this->consoleClient
            ),
            default => throw new \RuntimeException(sprintf('No handler found for command: %s', $commandName)),
        };

        $handler->setBoardPath($this->boardPath);
        $handler->setReviewFilePath($this->reviewFilePath);

        return $handler;
    }
}
