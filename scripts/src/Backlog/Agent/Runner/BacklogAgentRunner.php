<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Runner;

use SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncherRegistry;
use SoManAgent\Script\Backlog\Agent\Client\BacklogCommandRunner;
use SoManAgent\Script\Backlog\Agent\Client\ClaudeAgentLauncher;
use SoManAgent\Script\Backlog\Agent\Client\ProjectBacklogCommandRunner;
use SoManAgent\Script\Backlog\Agent\Client\CodexAgentLauncher;
use SoManAgent\Script\Backlog\Agent\Client\DirectSessionDriver;
use SoManAgent\Script\Backlog\Agent\Client\GeminiAgentLauncher;
use SoManAgent\Script\Backlog\Agent\Client\OpenCodeAgentLauncher;
use SoManAgent\Script\Backlog\Agent\Client\PosixProcessSignaler;
use SoManAgent\Script\Backlog\Agent\Client\ProcessSignaler;
use SoManAgent\Script\Backlog\Agent\Client\SessionDriverInterface;
use SoManAgent\Script\Backlog\Agent\Client\ShellProcessRunner;
use SoManAgent\Script\Backlog\Agent\Client\SystemInteractiveProcessRunner;
use SoManAgent\Script\Backlog\Agent\Client\TmuxSessionDriver;
use SoManAgent\Script\Backlog\Agent\Command\AbstractAgentCommand;
use SoManAgent\Script\Backlog\Agent\Command\AgentListCommand;
use SoManAgent\Script\Backlog\Agent\Command\AgentSessionsCommand;
use SoManAgent\Script\Backlog\Agent\Command\AgentStartCommand;
use SoManAgent\Script\Backlog\Agent\Command\AgentStatusCommand;
use SoManAgent\Script\Backlog\Agent\Command\AgentStopCommand;
use SoManAgent\Script\Backlog\Agent\Command\AgentWhoamiCommand;
use SoManAgent\Script\Backlog\Agent\Command\BacklogAgentPruneCommand;
use SoManAgent\Script\Backlog\Agent\Service\AgentCliOptionValidator;
use SoManAgent\Script\Backlog\Agent\Service\AgentCodeService;
use SoManAgent\Script\Backlog\Agent\Service\AgentContextBuilder;
use SoManAgent\Script\Backlog\Agent\Service\AgentDeveloperSelector;
use SoManAgent\Script\Backlog\Agent\Service\AgentLaunchPromptResolver;
use SoManAgent\Script\Backlog\Agent\Service\AgentModelResolver;
use SoManAgent\Script\Backlog\Agent\Service\AgentReviewerSelector;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\BacklogPaths;
use SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Backlog\Service\EntryRebaseService;
use SoManAgent\Script\Client\ConsoleClient;
use SoManAgent\Script\Client\FilesystemClient;
use SoManAgent\Script\Client\GitClient;
use SoManAgent\Script\Client\ProjectScriptClient;
use SoManAgent\Script\RetryPolicy;
use SoManAgent\Script\Runner\AbstractScriptRunner;
use SoManAgent\Script\Service\GitService;
use SoManAgent\Script\TextSlugger;

/**
 * Entry-point dispatcher for scripts/backlog-agent.php.
 *
 * Parses the subcommand, renders YAML-based help, and delegates to the matching
 * AbstractAgentCommand implementation. Help is driven by YAML resources under
 * scripts/resources/backlog-agent/.
 *
 * The session driver is selected by the environment variable BACKLOG_AGENT_SESSION_DRIVER:
 *   - tmux   (default): wraps sessions in named tmux sessions; SSH-resilient
 *   - direct: spawns clients via proc_open; degraded mode, no SSH resilience
 */
final class BacklogAgentRunner extends AbstractScriptRunner
{
    private const DEFAULT_WORKTREES_DIR = '.agent-worktrees';

    /** @var array<string, AbstractAgentCommand>|null */
    private ?array $commands = null;

    private ?AgentClientLauncherRegistry $registry = null;
    private ?AgentSessionService $sessionService = null;
    private ?AgentCodeService $codeService = null;
    private ?AgentContextBuilder $contextBuilder = null;
    private ?AgentCliOptionValidator $optionValidator = null;
    private ?AgentLaunchPromptResolver $launchPromptResolver = null;
    private ?AgentModelResolver $modelResolver = null;
    private ?BacklogBoardService $boardService = null;
    private ?BacklogWorktreeService $worktreeService = null;
    private ?ConsoleClient $consoleClient = null;
    private ?GitClient $gitClient = null;
    private ?SessionDriverInterface $sessionDriver = null;
    private ?ProcessSignaler $processSignaler = null;
    private ?BacklogCommandRunner $backlogCommandRunner = null;
    private ?EntryRebaseService $entryRebaseService = null;

    /**
     * {@inheritdoc}
     */
    protected function getName(): string
    {
        return 'backlog-agent';
    }

    /**
     * {@inheritdoc}
     */
    protected function printHelp(): void
    {
        $this->printYamlHelp();
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        $args = array_values(array_filter($args, fn(string $a): bool => $a !== '--force-current-worktree'));
        [$parsedArgs, $options] = $this->parseArgs($args);
        $subcommand = array_shift($parsedArgs) ?? '';

        if ($subcommand === '' || $subcommand === 'help') {
            $this->optionValidator()->assertGlobalOptionsAccepted($options);
            $target = $parsedArgs[0] ?? '';
            if ($target !== '') {
                $this->printYamlCommandHelp($target);

                return 0;
            }
            $this->printHelp();

            return 0;
        }

        $cmd = $this->commands()[$subcommand] ?? null;
        if ($cmd === null) {
            throw new \RuntimeException(sprintf("Unknown command: '%s'. Run 'php scripts/backlog-agent.php help' for the list.", $subcommand));
        }

        $this->optionValidator()->assertCommandOptionsAccepted($subcommand, $cmd->getOptions(), $options);

        if (isset($options[BacklogCliOption::HELP->value])) {
            $this->printYamlCommandHelp($subcommand);

            return 0;
        }

        return $cmd->handle($parsedArgs, $options);
    }

    /**
     * @return array<string, AbstractAgentCommand>
     */
    private function commands(): array
    {
        if ($this->commands === null) {
            $boardPath = BacklogPaths::boardPath($this->projectRoot);
            $worktreesRoot = $this->projectRoot . '/' . self::DEFAULT_WORKTREES_DIR;

            $this->commands = [
                'start' => new AgentStartCommand(
                    $this->projectRoot,
                    $worktreesRoot,
                    $boardPath,
                    $this->registry(),
                    $this->codeService($boardPath, $worktreesRoot),
                    $this->sessionService(),
                    $this->contextBuilder($boardPath),
                    $this->worktreeService($boardPath, $worktreesRoot),
                    new AgentReviewerSelector($this->boardService(), $this->sessionService(), $worktreesRoot),
                    new AgentDeveloperSelector($this->boardService()),
                    $this->boardService(),
                    $this->sessionDriver(),
                    $this->processSignaler(),
                    new ShellProcessRunner(),
                    $this->backlogCommandRunner(),
                    $this->entryRebaseService(),
                    $this->modelResolver(),
                    $this->launchPromptResolver(),
                ),
                'list' => new AgentListCommand(
                    $this->console,
                    $this->projectRoot,
                    $boardPath,
                    $this->sessionService(),
                    $this->boardService(),
                    $this->sessionDriver(),
                ),
                'status' => new AgentStatusCommand(
                    $this->console,
                    $this->projectRoot,
                    $boardPath,
                    $this->sessionService(),
                    $this->boardService(),
                    $this->sessionDriver(),
                ),
                'stop' => new AgentStopCommand(
                    $this->console,
                    $this->sessionService(),
                    $this->sessionDriver(),
                ),
                'whoami' => new AgentWhoamiCommand(
                    $this->console,
                    $boardPath,
                    $this->boardService(),
                ),
                'agent-history' => new AgentSessionsCommand(
                    $this->console,
                    $this->sessionService(),
                    $this->registry(),
                ),
                'prune' => new BacklogAgentPruneCommand(
                    $this->console,
                    $this->sessionService(),
                    $this->sessionDriver(),
                    $this->processSignaler(),
                ),
            ];
        }

        return $this->commands;
    }

    private function optionValidator(): AgentCliOptionValidator
    {
        if ($this->optionValidator === null) {
            $this->optionValidator = new AgentCliOptionValidator();
        }

        return $this->optionValidator;
    }

    private function modelResolver(): AgentModelResolver
    {
        if ($this->modelResolver === null) {
            $this->modelResolver = new AgentModelResolver($this->projectRoot . '/scripts/resources/backlog-agent/model-mapping.yaml');
        }

        return $this->modelResolver;
    }

    private function launchPromptResolver(): AgentLaunchPromptResolver
    {
        if ($this->launchPromptResolver === null) {
            $this->launchPromptResolver = new AgentLaunchPromptResolver($this->projectRoot . '/scripts/resources/backlog-agent/launch-prompts.yaml');
        }

        return $this->launchPromptResolver;
    }

    private function registry(): AgentClientLauncherRegistry
    {
        if ($this->registry === null) {
            $this->registry = new AgentClientLauncherRegistry();
            $this->registry->register(new ClaudeAgentLauncher(null, null, $this->projectRoot));
            $this->registry->register(new CodexAgentLauncher(null, null, null, $this->projectRoot));
            $this->registry->register(new OpenCodeAgentLauncher(null, $this->projectRoot));
            $this->registry->register(new GeminiAgentLauncher(null, null, $this->projectRoot));
        }

        return $this->registry;
    }

    private function sessionService(): AgentSessionService
    {
        if ($this->sessionService === null) {
            $this->sessionService = new AgentSessionService($this->projectRoot);
        }

        return $this->sessionService;
    }

    private function codeService(string $boardPath, string $worktreesRoot): AgentCodeService
    {
        if ($this->codeService === null) {
            $this->codeService = new AgentCodeService(
                $worktreesRoot,
                $boardPath,
                $this->boardService(),
                $this->sessionService(),
            );
        }

        return $this->codeService;
    }

    private function contextBuilder(string $boardPath): AgentContextBuilder
    {
        if ($this->contextBuilder === null) {
            $this->contextBuilder = new AgentContextBuilder($this->projectRoot, $boardPath, $this->boardService());
        }

        return $this->contextBuilder;
    }

    private function boardService(): BacklogBoardService
    {
        if ($this->boardService === null) {
            $this->boardService = new BacklogBoardService(new TextSlugger(), new FilesystemClient(), false);
        }

        return $this->boardService;
    }

    private function worktreeService(string $boardPath, string $worktreesRoot): BacklogWorktreeService
    {
        if ($this->worktreeService === null) {
            $this->worktreeService = new BacklogWorktreeService(
                $this->projectRoot,
                $worktreesRoot,
                false,
                (string) getenv('DATABASE_URL'),
                $this->boardService(),
                $this->consoleClient(),
                $this->gitClient(),
                new ProjectScriptClient($this->consoleClient()),
                new FilesystemClient(),
            );
        }

        return $this->worktreeService;
    }

    private function consoleClient(): ConsoleClient
    {
        if ($this->consoleClient === null) {
            $this->consoleClient = new ConsoleClient(
                $this->projectRoot,
                false,
                $this->app,
                fn(string $m) => null,
            );
        }

        return $this->consoleClient;
    }

    private function gitClient(): GitClient
    {
        if ($this->gitClient === null) {
            $this->gitClient = new GitClient(false, $this->consoleClient(), new RetryPolicy());
        }

        return $this->gitClient;
    }

    /**
     * Creates the session driver selected by BACKLOG_AGENT_SESSION_DRIVER (default: tmux).
     */
    private function sessionDriver(): SessionDriverInterface
    {
        if ($this->sessionDriver === null) {
            $driverName = (string) (getenv('BACKLOG_AGENT_SESSION_DRIVER') ?: 'tmux');

            $this->sessionDriver = match ($driverName) {
                'direct' => new DirectSessionDriver(
                    new SystemInteractiveProcessRunner(),
                    $this->processSignaler(),
                    $this->console,
                ),
                default => new TmuxSessionDriver(new ShellProcessRunner(), $this->console),
            };
        }

        return $this->sessionDriver;
    }

    private function processSignaler(): ProcessSignaler
    {
        if ($this->processSignaler === null) {
            $this->processSignaler = new PosixProcessSignaler();
        }

        return $this->processSignaler;
    }

    private function backlogCommandRunner(): BacklogCommandRunner
    {
        if ($this->backlogCommandRunner === null) {
            $this->backlogCommandRunner = new ProjectBacklogCommandRunner(
                new ProjectScriptClient($this->consoleClient()),
                $this->projectRoot,
            );
        }

        return $this->backlogCommandRunner;
    }

    private function entryRebaseService(): EntryRebaseService
    {
        if ($this->entryRebaseService === null) {
            $gitService = new GitService(false, $this->console, $this->gitClient(), static function (string $m): void {});
            $this->entryRebaseService = new EntryRebaseService($this->boardService(), $gitService);
        }

        return $this->entryRebaseService;
    }
}
