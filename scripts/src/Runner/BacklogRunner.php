<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Backlog\BacklogCommandFactory;
use SoManAgent\Script\Backlog\BacklogCommandHelp;
use SoManAgent\Script\Backlog\BacklogCliOption;
use SoManAgent\Script\Backlog\BacklogCommandName;
use SoManAgent\Script\Backlog\BacklogEntryService;
use SoManAgent\Script\Backlog\BacklogEntryResolver;
use SoManAgent\Script\Backlog\BacklogGitWorkflow;
use SoManAgent\Script\Backlog\BacklogReviewBodyFormatter;
use SoManAgent\Script\Backlog\BacklogReviewFile;
use SoManAgent\Script\Backlog\BacklogWorktreeManager;
use SoManAgent\Script\Backlog\PullRequestService;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Client\GitHubClient;
use SoManAgent\Script\Client\ProjectScriptClient;
use SoManAgent\Script\Environment;
use SoManAgent\Script\TextSlugger;

/**
 * Local backlog workflow orchestrator.
 */
final class BacklogRunner extends AbstractScriptRunner
{
    private const DEFAULT_BOARD_PATH = 'local/backlog-board.md';
    private const DEFAULT_REVIEW_FILE_PATH = 'local/backlog-review.md';

    private ?string $boardPath = null;
    private ?string $reviewFilePath = null;

    private array $ignoredExceptions = [
        'Operation timed out',
        'Temporary failure in name resolution',
    ];

    private ?BacklogCommandHelp $commandHelp = null;
    private ?BacklogCommandFactory $commandFactory = null;
    private ?BacklogEntryResolver $entryResolver = null;
    private ?BacklogEntryService $entryService = null;
    private ?ConsoleClient $consoleClient = null;
    private ?GitClient $gitClient = null;
    private ?ProjectScriptClient $projectScriptClient = null;
    private ?GitHubClient $gitHubClient = null;
    private ?BacklogWorktreeManager $worktreeManager = null;
    private ?BacklogGitWorkflow $gitWorkflow = null;
    private ?PullRequestService $pullRequestService = null;
    private ?BacklogReviewBodyFormatter $reviewBodyFormatter = null;

    protected function getDescription(): string
    {
        return 'Local backlog workflow orchestrator.';
    }

    protected function getCommands(): array
    {
        return $this->commandHelp()->getCommands();
    }

    protected function getOptions(): array
    {
        return $this->commandHelp()->getOptions($this->getExecutionModeOptions());
    }

    protected function getUsageExamples(): array
    {
        return $this->commandHelp()->getUsageExamples();
    }

    /**
     * Executes one backlog workflow command.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        [$parsedArgs, $options] = $this->parseArgs($args);
        $command = array_shift($parsedArgs) ?? '';
        $commandArgs = $parsedArgs;
        $this->configureExecutionModes($options);
        $this->configureTestFileOverrides($options);

        if ($command === '') {
            $this->printHelp();

            return 0;
        }

        if ($command === BacklogCommandName::HELP->value) {
            $targetCommand = $commandArgs[0] ?? '';
            if ($targetCommand === '') {
                $this->printHelp();

                return 0;
            }

            $this->printCommandHelp($targetCommand);

            return 0;
        }

        if (isset($options['help'])) {
            $this->printCommandHelp($command);

            return 0;
        }

        try {
            return $this->handleCommand($command, $commandArgs, $options);
        } catch (\Exception $e) {
            $this->console->fail($e->getMessage());

            return 1;
        }
    }

    private function handleCommand(string $command, array $commandArgs, array $options): int
    {
        $this->commandFactory()->createHandler($command)->handle($commandArgs, $options);

        return 0;
    }

    private function parseArgs(array $args): array
    {
        $parsedArgs = [];
        $options = [];

        for ($i = 0, $c = count($args); $i < $c; $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--')) {
                $key = ltrim($arg, '-');
                $val = $args[$i + 1] ?? true;
                if ($val !== true && str_starts_with((string) $val, '--')) {
                    $val = true;
                } else {
                    $i++;
                }
                $options[$key] = $val;
            } else {
                $parsedArgs[] = $arg;
            }
        }

        return [$parsedArgs, $options];
    }

    private function configureTestFileOverrides(array $options): void
    {
        if (!($options[BacklogCliOption::TEST_MODE->value] ?? false)) {
            return;
        }

        if (isset($options[BacklogCliOption::BOARD_FILE->value])) {
            $this->boardPath = $this->validateTestFileOverride((string) $options[BacklogCliOption::BOARD_FILE->value], BacklogCliOption::BOARD_FILE->value);
        }

        if (isset($options[BacklogCliOption::REVIEW_FILE->value])) {
            $this->reviewFilePath = $this->validateTestFileOverride((string) $options[BacklogCliOption::REVIEW_FILE->value], BacklogCliOption::REVIEW_FILE->value);
        }
    }

    private function validateTestFileOverride(string $path, string $option): string
    {
        if (!str_starts_with($path, 'local/tmp/')) {
            throw new \RuntimeException("Backlog test file override for --{$option} must be in local/tmp/.");
        }

        return $this->projectRoot . '/' . $path;
    }

    private function printCommandHelp(string $command): void
    {
        echo $this->commandHelp()->renderCommandHelp($command);
    }

    private function commandHelp(): BacklogCommandHelp
    {
        if ($this->commandHelp === null) {
            $this->commandHelp = new BacklogCommandHelp();
        }

        return $this->commandHelp;
    }

    private function commandFactory(): BacklogCommandFactory
    {
        if ($this->commandFactory === null) {
            $this->commandFactory = new BacklogCommandFactory(
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->worktreeManager(),
                $this->entryService(),
                $this->entryResolver(),
                $this->consoleClient(),
                $this->featureSlugger(),
                $this->reviewBodyFormatter(),
                $this->gitWorkflow(),
                $this->pullRequestService(),
                $this->boardPath(),
                $this->reviewFilePath()
            );
        }

        return $this->commandFactory;
    }

    private function boardPath(): string
    {
        return $this->boardPath ?? ($this->projectRoot . '/' . self::DEFAULT_BOARD_PATH);
    }

    private function reviewFilePath(): string
    {
        return $this->reviewFilePath ?? ($this->projectRoot . '/' . self::DEFAULT_REVIEW_FILE_PATH);
    }

    private function entryResolver(): BacklogEntryResolver
    {
        if ($this->entryResolver === null) {
            $this->entryResolver = new BacklogEntryResolver($this->featureSlugger());
        }

        return $this->entryResolver;
    }

    private function entryService(): BacklogEntryService
    {
        if ($this->entryService === null) {
            $this->entryService = new BacklogEntryService($this->featureSlugger(), $this->entryResolver());
        }

        return $this->entryService;
    }

    private function consoleClient(): ConsoleClient
    {
        if ($this->consoleClient === null) {
            $this->consoleClient = new ConsoleClient(
                $this->projectRoot,
                $this->dryRun,
                $this->app,
                function (string $message): void {
                    $this->logVerbose($message);
                }
            );
        }

        return $this->consoleClient;
    }

    private function gitClient(): GitClient
    {
        if ($this->gitClient === null) {
            $this->gitClient = new GitClient(
                $this->dryRun,
                $this->consoleClient(),
                $this->ignoredExceptions
            );
        }

        return $this->gitClient;
    }

    private function projectScriptClient(): ProjectScriptClient
    {
        if ($this->projectScriptClient === null) {
            $this->projectScriptClient = new ProjectScriptClient($this->consoleClient());
        }

        return $this->projectScriptClient;
    }

    private function gitHubClient(): GitHubClient
    {
        if ($this->gitHubClient === null) {
            $this->gitHubClient = new GitHubClient(
                $this->dryRun,
                $this->projectScriptClient(),
                $this->ignoredExceptions,
                0,
                0,
                0
            );
        }

        return $this->gitHubClient;
    }

    private function worktreeManager(): BacklogWorktreeManager
    {
        if ($this->worktreeManager === null) {
            $this->worktreeManager = new BacklogWorktreeManager(
                $this->projectRoot,
                $this->dryRun,
                (string) getenv('DATABASE_URL'),
                $this->entryResolver(),
                $this->consoleClient(),
                $this->gitClient(),
                $this->projectScriptClient()
            );
        }

        return $this->worktreeManager;
    }

    private function gitWorkflow(): BacklogGitWorkflow
    {
        if ($this->gitWorkflow === null) {
            $this->gitWorkflow = new BacklogGitWorkflow(
                $this->dryRun,
                $this->consoleClient(),
                $this->console,
                $this->gitClient(),
                $this->pullRequestService(),
                function (string $message): void {
                    $this->logVerbose($message);
                }
            );
        }

        return $this->gitWorkflow;
    }

    private function pullRequestService(): PullRequestService
    {
        if ($this->pullRequestService === null) {
            $this->pullRequestService = new PullRequestService(
                $this->dryRun,
                'Head branch is invalid',
                $this->gitClient(),
                $this->gitHubClient(),
                $this->entryService(),
                0,
                0,
                0
            );
        }

        return $this->pullRequestService;
    }

    private function reviewBodyFormatter(): BacklogReviewBodyFormatter
    {
        if ($this->reviewBodyFormatter === null) {
            $this->reviewBodyFormatter = new BacklogReviewBodyFormatter($this->projectRoot);
        }

        return $this->reviewBodyFormatter;
    }

    private function featureSlugger(): TextSlugger
    {
        return new TextSlugger();
    }

    private function logVerbose(string $message): void
    {
        if ($this->verbose) {
            $this->console->line('  [debug] ' . $message);
        }
    }
}
