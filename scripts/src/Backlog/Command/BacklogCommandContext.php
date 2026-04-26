<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Command;

use SoManAgent\Script\Backlog\BacklogCommandFactory;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogGitWorkflow;
use SoManAgent\Script\Backlog\BacklogReviewBodyFormatter;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\PullRequestManager;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Console;
use SoManAgent\Script\TextSlugger;

/**
 * Context containing all shared services for backlog commands.
 */
final class BacklogCommandContext
{
    private Console $console;

    private bool $dryRun;

    private string $projectRoot;

    private BacklogWorktreeManager $worktreeManager;

    private BacklogEntryService $entryService;

    private BacklogEntryResolver $entryResolver;

    private ConsoleClient $consoleClient;

    private TextSlugger $featureSlugger;

    private BacklogReviewBodyFormatter $reviewBodyFormatter;

    private BacklogGitWorkflow $gitWorkflow;

    private PullRequestManager $pullRequestManager;

    private BacklogCommandFactory $commandFactory;

    public function __construct(
        Console $console,
        bool $dryRun,
        string $projectRoot,
        BacklogWorktreeManager $worktreeManager,
        BacklogEntryService $entryService,
        BacklogEntryResolver $entryResolver,
        ConsoleClient $consoleClient,
        TextSlugger $featureSlugger,
        BacklogReviewBodyFormatter $reviewBodyFormatter,
        BacklogGitWorkflow $gitWorkflow,
        PullRequestManager $pullRequestManager,
        BacklogCommandFactory $commandFactory
    ) {
        $this->console = $console;
        $this->dryRun = $dryRun;
        $this->projectRoot = $projectRoot;
        $this->worktreeManager = $worktreeManager;
        $this->entryService = $entryService;
        $this->entryResolver = $entryResolver;
        $this->consoleClient = $consoleClient;
        $this->featureSlugger = $featureSlugger;
        $this->reviewBodyFormatter = $reviewBodyFormatter;
        $this->gitWorkflow = $gitWorkflow;
        $this->pullRequestManager = $pullRequestManager;
        $this->commandFactory = $commandFactory;
    }

    public function getConsole(): Console
    {
        return $this->console;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    public function getWorktreeManager(): BacklogWorktreeManager
    {
        return $this->worktreeManager;
    }

    public function getEntryService(): BacklogEntryService
    {
        return $this->entryService;
    }

    public function getEntryResolver(): BacklogEntryResolver
    {
        return $this->entryResolver;
    }

    public function getConsoleClient(): ConsoleClient
    {
        return $this->consoleClient;
    }

    public function getFeatureSlugger(): TextSlugger
    {
        return $this->featureSlugger;
    }

    public function getReviewBodyFormatter(): BacklogReviewBodyFormatter
    {
        return $this->reviewBodyFormatter;
    }

    public function getGitWorkflow(): BacklogGitWorkflow
    {
        return $this->gitWorkflow;
    }

    public function getPullRequestManager(): PullRequestManager
    {
        return $this->pullRequestManager;
    }

    public function getCommandFactory(): BacklogCommandFactory
    {
        return $this->commandFactory;
    }
}
