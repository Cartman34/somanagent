<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Command;

use SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncherRegistry;
use SoManAgent\Script\Backlog\Agent\Client\BacklogCommandRunner;
use SoManAgent\Script\Backlog\Agent\Client\ProcessRunner;
use SoManAgent\Script\Backlog\Agent\Client\ProcessSignaler;
use SoManAgent\Script\Backlog\Agent\Client\SessionDriverInterface;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Enum\WaOccupantChoice;
use SoManAgent\Script\Backlog\Agent\Exception\ActiveSessionException;
use SoManAgent\Script\Backlog\Agent\Exception\ClientNotInstalledException;
use SoManAgent\Script\Backlog\Agent\Exception\EntryNotReservableException;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Backlog\Agent\Model\ResolvedModel;
use SoManAgent\Script\Backlog\Agent\Model\ReviewerPickOutcome;
use SoManAgent\Script\Backlog\Agent\Service\AgentCodeService;
use SoManAgent\Script\Backlog\Agent\Service\AgentContextBuilder;
use SoManAgent\Script\Backlog\Agent\Service\AgentDeveloperSelector;
use SoManAgent\Script\Backlog\Agent\Service\AgentLaunchPromptResolver;
use SoManAgent\Script\Backlog\Agent\Service\AgentModelResolver;
use SoManAgent\Script\Backlog\Agent\Service\AgentReviewerSelector;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Model\BoardEntryMatch;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;
use SoManAgent\Script\Backlog\Service\EntryRebaseService;

/**
 * Starts or re-attaches an AI agent session in a dedicated worktree.
 *
 * When a sessions.json entry already exists for the requested code, start dispatches
 * automatically based on the observed liveness of that session:
 *   - Live session (driver isAlive + WA present) → re-attach
 *   - Ghost session (driver dead or WA absent)   → silent cleanup, then create a new session
 *
 * Pass --force-new to drop a live session and force a fresh start.
 *
 * Usage:
 *   php scripts/backlog-agent.php start <client> --developer [--code=<dXX>] [--reset] [--force-new]
 *   php scripts/backlog-agent.php start <client> --reviewer [--code=<rXX>] [--feature=<slug>] [--task=<feature/task>] [--developer=<dXX>] [--force]
 *   php scripts/backlog-agent.php start <client> --manager  [--code=<mXX>]
 */
final class AgentStartCommand extends AbstractAgentCommand
{
    private string $projectRoot;
    private string $worktreesRoot;
    private string $boardPath;
    private AgentClientLauncherRegistry $registry;
    private AgentCodeService $codeService;
    private AgentSessionService $sessionService;
    private AgentContextBuilder $contextBuilder;
    private BacklogWorktreeService $worktreeService;
    private AgentReviewerSelector $reviewerSelector;
    private AgentDeveloperSelector $developerSelector;
    private BacklogBoardService $boardService;
    private SessionDriverInterface $sessionDriver;
    private ProcessSignaler $signaler;
    private ProcessRunner $shellRunner;
    private BacklogCommandRunner $backlogCommandRunner;
    private ?AgentModelResolver $modelResolver;
    private AgentLaunchPromptResolver $launchPromptResolver;
    private EntryRebaseService $entryRebaseService;
    private bool $watchStopRequested = false;

    /**
     * Optional override for the interactive WA-occupant conflict prompt.
     *
     * Signature: callable(AgentSession $existing, BoardEntry $entry, bool $hasMoreCandidates): WaOccupantChoice
     *
     * When null, the production stdin-based prompter is used. Inject a fake in tests.
     *
     * @var (callable(AgentSession, BoardEntry, bool): WaOccupantChoice)|null
     */
    private $waConflictPrompter;

    /**
     * @param string $projectRoot
     * @param string $worktreesRoot
     * @param string $boardPath
     * @param AgentClientLauncherRegistry $registry
     * @param AgentCodeService $codeService
     * @param AgentSessionService $sessionService
     * @param AgentContextBuilder $contextBuilder
     * @param BacklogWorktreeService $worktreeService
     * @param AgentReviewerSelector $reviewerSelector
     * @param AgentDeveloperSelector $developerSelector Used to check for an active entry or auto-select the first queued task
     * @param BacklogBoardService $boardService
     * @param SessionDriverInterface $sessionDriver
     * @param ProcessSignaler $signaler Used for liveness checks in attach and ghost-cleanup logic
     * @param ProcessRunner $shellRunner Used to check for local changes in the worktree
     * @param BacklogCommandRunner $backlogCommandRunner Used to delegate review-next, review-cancel, start, and release under the backlog lock
     * @param EntryRebaseService $entryRebaseService Handles approved-entry rebase before developer session launch; use NullEntryRebaseService in tests that do not exercise the approved path
     * @param AgentModelResolver|null $modelResolver Resolves role/client model tier and effort into CLI args
     * @param AgentLaunchPromptResolver|null $launchPromptResolver Resolves role-specific initial prompts for auto-picked entries; defaults to the bundled scripts resource
     * @param (callable(AgentSession, BoardEntry, bool): WaOccupantChoice)|null $waConflictPrompter Override for the interactive WA-occupant conflict prompt; when null the production stdin prompter is used
     */
    public function __construct(
        string $projectRoot,
        string $worktreesRoot,
        string $boardPath,
        AgentClientLauncherRegistry $registry,
        AgentCodeService $codeService,
        AgentSessionService $sessionService,
        AgentContextBuilder $contextBuilder,
        BacklogWorktreeService $worktreeService,
        AgentReviewerSelector $reviewerSelector,
        AgentDeveloperSelector $developerSelector,
        BacklogBoardService $boardService,
        SessionDriverInterface $sessionDriver,
        ProcessSignaler $signaler,
        ProcessRunner $shellRunner,
        BacklogCommandRunner $backlogCommandRunner,
        EntryRebaseService $entryRebaseService,
        ?AgentModelResolver $modelResolver = null,
        ?AgentLaunchPromptResolver $launchPromptResolver = null,
        ?callable $waConflictPrompter = null,
    ) {
        $this->projectRoot = $projectRoot;
        $this->worktreesRoot = $worktreesRoot;
        $this->boardPath = $boardPath;
        $this->registry = $registry;
        $this->codeService = $codeService;
        $this->sessionService = $sessionService;
        $this->contextBuilder = $contextBuilder;
        $this->worktreeService = $worktreeService;
        $this->reviewerSelector = $reviewerSelector;
        $this->developerSelector = $developerSelector;
        $this->boardService = $boardService;
        $this->sessionDriver = $sessionDriver;
        $this->signaler = $signaler;
        $this->shellRunner = $shellRunner;
        $this->backlogCommandRunner = $backlogCommandRunner;
        $this->entryRebaseService = $entryRebaseService;
        $this->modelResolver = $modelResolver;
        $this->launchPromptResolver = $launchPromptResolver ?? new AgentLaunchPromptResolver(
            dirname(__DIR__, 4) . '/resources/backlog-agent/launch-prompts.yaml',
        );
        $this->waConflictPrompter = $waConflictPrompter;
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions(): array
    {
        return [
            ['name' => '--developer', 'description' => 'Launch as developer role'],
            ['name' => '--reviewer', 'description' => 'Launch as reviewer role (reuses the developer WA)'],
            ['name' => '--manager', 'description' => 'Launch as manager role'],
            ['name' => '--code=<code>', 'description' => 'Explicit agent code (e.g. d04, r01). Omit to auto-allocate.'],
            ['name' => '--tier=<tier>', 'description' => 'Model tier override: economy, balanced, premium'],
            ['name' => '--effort=<effort>', 'description' => 'Reasoning effort override: low, medium, high'],
            ['name' => '--model=<model>', 'description' => 'Raw model name override passed directly to the client'],
            ['name' => '--reset', 'description' => 'Remove and recreate the worktree before launching (developer only; refuses if dirty)'],
            ['name' => '--force-new', 'description' => 'Drop a live session and create a fresh one (developer only)'],
            ['name' => '--watch', 'description' => 'Wait for an eligible developer or reviewer entry before launching'],
            ['name' => '--watch-interval=<sec>', 'description' => 'Polling interval for --watch, in seconds (default: 2)'],
            ['name' => '--loop', 'description' => 'With --watch, return to watching after a clean client exit'],
            ['name' => '--feature=<slug>', 'description' => 'Reviewer: target the feature entry at stage=review with this slug'],
            ['name' => '--task=<feature/task>', 'description' => 'Reviewer: target the task entry at stage=review with this reference'],
            ['name' => '--developer=<dXX>', 'description' => 'Reviewer: target the active entry assigned to this developer code'],
            ['name' => '--force', 'description' => 'Reviewer: proceed even when another reviewer session is active in the target WA'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $args, array $options): int
    {
        $originalCwd = getcwd();
        $clientValue = array_shift($args) ?? '';
        if ($clientValue === '') {
            throw new \RuntimeException('Missing required argument: <client>. One of: claude, codex, opencode, gemini.');
        }

        $client = AgentClient::tryFrom($clientValue);
        if ($client === null) {
            throw new \RuntimeException(sprintf(
                "Unknown client '%s'. Allowed values: claude, codex, opencode, gemini.",
                $clientValue,
            ));
        }

        $watch = isset($options[BacklogCliOption::WATCH->value]);
        $loop = isset($options[BacklogCliOption::LOOP->value]);
        $role = $this->resolveRole($options, $watch);
        $reset = isset($options[BacklogCliOption::RESET->value]);
        $forceNew = isset($options[BacklogCliOption::FORCE_NEW->value]);
        $tierOverride = $this->getSingleOption($options, 'tier');
        $effortOverride = $this->getSingleOption($options, 'effort');
        $modelOverride = $this->getSingleOption($options, 'model');

        if ($loop && !$watch) {
            throw new \RuntimeException('--loop requires --watch.');
        }

        if ($role === AgentRole::MANAGER && $watch) {
            throw new \RuntimeException('--watch is only supported for developer and reviewer launches.');
        }

        if ($reset && $role !== AgentRole::DEVELOPER) {
            throw new \RuntimeException('--reset is only allowed with --developer.');
        }

        if ($forceNew && $role !== AgentRole::DEVELOPER) {
            throw new \RuntimeException('--force-new is only allowed with --developer.');
        }

        // Validate driver dependencies (e.g. tmux binary) before any worktree work.
        $this->sessionDriver->checkDependencies();

        $launcher = $this->registry->get($client);

        if (!$launcher->isAvailable()) {
            throw new ClientNotInstalledException($client);
        }

        $codeOption = $this->getSingleOption($options, 'code');

        if ($codeOption !== null && $role === null) {
            throw new \RuntimeException('--code requires an explicit role when --watch is used.');
        }

        do {
            $cycleRole = $role;
            $watchClaimedRef = null;
            if ($watch) {
                $watchResult = $this->watchForWork($role, $codeOption, $this->resolveWatchInterval($options));
                if ($watchResult === null) {
                    return 130;
                }
                [$cycleRole, $code, $watchClaimedRef] = $watchResult;
            } else {
                if ($cycleRole === null) {
                    throw new \LogicException('Non-watch launch requires a resolved role.');
                }
                if ($codeOption !== null) {
                    $this->codeService->validate($codeOption, $cycleRole);
                    $code = $codeOption;
                } else {
                    $code = $this->codeService->allocateForRole($cycleRole);
                }
            }

            $resolvedModel = $this->modelResolver?->resolve($client, $cycleRole, $tierOverride, $effortOverride, $modelOverride);
            if ($resolvedModel !== null) {
                foreach ($resolvedModel->warnings as $warning) {
                    echo "Warning: {$warning}\n";
                }
            }

            $exitCode = $this->launchCycle(
                $client,
                $launcher,
                $cycleRole,
                $code,
                $options,
                $reset,
                $forceNew,
                $resolvedModel,
                $originalCwd,
                $watchClaimedRef,
            );

            if (!$loop || $exitCode !== 0) {
                return $exitCode;
            }
        } while (true);
    }

    /**
     * Runs one complete create/resume/launch cycle after the role and code are known.
     *
     * @param array<string, string|bool|array<bool|string>> $options
     */
    private function launchCycle(
        AgentClient $client,
        \SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncher $launcher,
        AgentRole $role,
        string $code,
        array $options,
        bool $reset,
        bool $forceNew,
        ?ResolvedModel $resolvedModel,
        false|string $originalCwd,
        ?string $watchClaimedRef = null,
    ): int {
        // Resolve existing session entry and dispatch: attach live, clean ghost, or proceed to create.
        $existingSession = $this->sessionService->get($code);

        if ($existingSession !== null) {
            $isDriverAlive = $this->sessionDriver->isAlive($existingSession);
            $isWorktreePresent = is_dir($existingSession->worktree);
            $isLive = $isDriverAlive && $isWorktreePresent;

            if ($isLive && !$forceNew) {
                return $this->handleAttach($existingSession, $originalCwd);
            }

            if ($isLive) {
                // --force-new: drop the live session and create a fresh one.
                echo sprintf("Dropping live session for %s, creating new session.\n", $code);
                if ($this->sessionDriver->sessionExists($code)) {
                    $this->sessionDriver->kill($code);
                }
            } else {
                // Ghost session (driver dead or WA absent): silent cleanup.
                echo sprintf("Stale session for %s cleaned, creating new session.\n", $code);
            }

            $this->sessionService->remove($code);
        }

        // Refuse when the driver already tracks a live session for this code (e.g. orphan tmux session).
        if ($this->sessionDriver->sessionExists($code)) {
            throw new \RuntimeException(sprintf(
                "A live driver session already exists for agent code '%s'.\n" .
                "Stop it first or use a different code:\n" .
                "  php scripts/backlog-agent.php stop --code=%s",
                $code,
                $code,
            ));
        }

        $takenEntryRef = null;
        $takenReviewerCode = null;
        $takenWorkStartRef = null;
        $takenWorkStartDevCode = null;
        $initialPrompt = null;

        if ($role === AgentRole::REVIEWER) {
            if ($watchClaimedRef !== null) {
                $worktree = $this->prepareClaimedReviewerEntry($code);
                $takenEntryRef = $watchClaimedRef;
                $takenReviewerCode = $code;
                $initialPrompt = $this->launchPromptResolver->resolveStageDecision(AgentRole::REVIEWER, BacklogBoard::STAGE_PENDING_REVIEW)->getPrompt();
            } else {
                $reviewerOutcome = $this->prepareReviewerMode($options, $code);

                if ($reviewerOutcome->isQuit()) {
                    return 0;
                }

                if ($reviewerOutcome->isAdopt()) {
                    $adoptedSession = $reviewerOutcome->getAdoptSession();
                    $adoptRollbackRef = $reviewerOutcome->getTakenEntryRef();
                    $adoptRollbackCode = $reviewerOutcome->getTakenReviewerCode();
                    try {
                        return $this->handleAttach($adoptedSession, $originalCwd);
                    } catch (\Throwable $e) {
                        $this->rollbackReviewTransition($adoptRollbackRef, $adoptRollbackCode);
                        throw $e;
                    }
                }

                $worktree = $reviewerOutcome->getWorktree();
                $takenEntryRef = $reviewerOutcome->getTakenEntryRef();
                $takenReviewerCode = $reviewerOutcome->getTakenReviewerCode();
                $reviewerStage = $takenEntryRef !== null ? BacklogBoard::STAGE_PENDING_REVIEW : BacklogBoard::STAGE_REVIEWING;
                $initialPrompt = $this->launchPromptResolver->resolveStageDecision(AgentRole::REVIEWER, $reviewerStage)->getPrompt();
            }
        } elseif ($role === AgentRole::MANAGER) {
            $worktree = $this->projectRoot;
        } else {
            $worktree = $this->worktreesRoot . '/' . $code;
            if ($reset) {
                if (is_dir($worktree) && $this->hasLocalChanges($worktree)) {
                    throw new \RuntimeException(sprintf('Worktree %s is dirty. Clean it before --reset.', $worktree));
                }
                $this->worktreeService->removeAgentWorktreeForRestore($code);
            }
            if ($watchClaimedRef !== null) {
                $takenWorkStartRef = $watchClaimedRef;
                $takenWorkStartDevCode = $code;
            } else {
                [$takenWorkStartRef, $takenWorkStartDevCode] = $this->prepareDeveloperMode($code);
            }

            if ($takenWorkStartRef !== null) {
                // Auto-picked new entry from todo
                $initialPrompt = $this->launchPromptResolver->resolveStageDecision(AgentRole::DEVELOPER, null)->getPrompt();
            } else {
                // Resuming an existing active entry — resolve prompt from stage
                $resumeBoard = $this->boardService->loadBoard($this->boardPath);
                $activeMatch = $this->developerSelector->findOwnedActiveEntry($resumeBoard, $code);
                if ($activeMatch !== null) {
                    $stage = $this->boardService->getNormalizedStage($activeMatch->getEntry()->getStage());
                    $decision = $this->launchPromptResolver->resolveStageDecision(AgentRole::DEVELOPER, $stage);

                    if ($decision->isRefusal()) {
                        echo $decision->getMessage() . "\n";
                        return 1;
                    }

                    if ($decision->isLauncherHandled()) {
                        // Approved entry: attempt rebase before launching the agent
                        $rebaseResult = $this->entryRebaseService->rebase($activeMatch->getEntry(), $worktree, $resumeBoard);
                        if (!$rebaseResult->isConflict()) {
                            echo $rebaseResult->getMessage() . "\n";
                            return 0;
                        }
                        // Conflict: launch the agent with a dedicated conflict prompt
                        $initialPrompt = $this->launchPromptResolver->resolveConflictPrompt();
                    } else {
                        $initialPrompt = $decision->getPrompt();
                    }
                }
            }

            $this->worktreeService->prepareAgentWorktree($code);
        }

        try {
            $contextFilePath = $this->contextBuilder->build($worktree, $code, $role);

            $launcher->prepareWorktree($worktree, $contextFilePath);

            $baseEnv = $this->buildBaseEnv($code, $role, $client);
            $env = $launcher->buildEnvironment($baseEnv, $contextFilePath);

            [$bin, $binArgs] = $launcher->buildLaunchCommand(
                $worktree,
                $contextFilePath,
                $role,
                null,
                false,
                $resolvedModel,
                $initialPrompt,
            );

            $this->sessionService->create($code, $client, $role, (int) getmypid(), $worktree);
        } catch (\Throwable $e) {
            $this->rollbackReviewTransition($takenEntryRef, $takenReviewerCode);
            $this->rollbackWorkStart($takenWorkStartRef, $takenWorkStartDevCode);
            throw $e;
        }

        chdir($worktree);

        $sessionSvc = $this->sessionService;
        $driverName = $this->sessionDriver->driverName();
        try {
            $exitCode = $this->sessionDriver->launch(
                $code,
                $role,
                $client,
                $bin,
                $binArgs,
                $worktree,
                $env,
                static function (int $clientPid, ?string $tmuxSession) use ($sessionSvc, $code, $role, $client, $driverName, $bin, $binArgs): void {
                    $sessionSvc->updateClientPid($code, $clientPid > 0 ? $clientPid : null);
                    if ($tmuxSession !== null) {
                        $sessionSvc->updateTmuxSession($code, $tmuxSession);
                    }
                    if ($clientPid > 0) {
                        $sessionSvc->logLaunch($code, $role, $client, $driverName, $bin, $binArgs, $clientPid);
                    }
                },
            );
        } finally {
            // Restore the original working directory after the session ends.
            // The worktree may have been removed during the session (e.g. by a concurrent
            // worktree-clean); any subprocess spawned after this point (isAlive, captureCurrentSessionId)
            // inherits PHP's cwd — a deleted directory triggers "getcwd() failed" on the terminal.
            if ($originalCwd !== false) {
                chdir($originalCwd);
            }
        }

        $capturedId = $launcher->captureCurrentSessionId($worktree);
        if ($capturedId !== null) {
            $this->sessionService->updateSessionId($code, $capturedId);
        }

        $session = $this->sessionService->get($code);
        if ($session !== null && $this->sessionDriver->isAlive($session)) {
            echo sprintf("Session detached. Use 'start --code=%s' to reconnect.\n", $code);
            return 0;
        }

        $this->sessionService->remove($code);

        return $exitCode;
    }

    /**
     * Polls the board until a claimable target is reserved, then returns the elected role/code.
     *
     * @return array{0: AgentRole, 1: string, 2: string}|null
     */
    private function watchForWork(?AgentRole $requestedRole, ?string $codeOption, int $intervalSeconds): ?array
    {
        $spinner = $this->selectSpinnerFrames();
        $tick = 0;
        $printed = false;
        $previousSigintHandler = null;
        $this->watchStopRequested = false;

        if (function_exists('pcntl_signal')) {
            $previousSigintHandler = function_exists('pcntl_signal_get_handler') ? pcntl_signal_get_handler(SIGINT) : null;
            pcntl_signal(SIGINT, function (): void {
                $this->watchStopRequested = true;
            });
        }

        try {
            while (true) {
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                if ($this->watchStopRequested) {
                    if ($printed) {
                        echo "\r" . str_repeat(' ', 24) . "\r";
                    }
                    echo "Watch stopped.\n";

                    return null;
                }

                $frame = $spinner[$tick % count($spinner)];
                echo "\r{$frame} Watching for work...";
                $printed = true;

                $board = $this->boardService->loadBoard($this->boardPath);
                $roles = $requestedRole !== null
                    ? [$requestedRole]
                    : [AgentRole::REVIEWER, AgentRole::DEVELOPER];

                foreach ($roles as $role) {
                    $code = $codeOption;
                    if ($code !== null) {
                        $this->codeService->validate($code, $role);
                    } else {
                        $code = $this->codeService->allocateForRole($role);
                    }

                    $entryRef = $role === AgentRole::REVIEWER
                        ? $this->tryWatchReviewerClaim($board, $code)
                        : $this->tryWatchDeveloperClaim($board, $code);

                    if ($entryRef !== null) {
                        echo "\r" . str_repeat(' ', 24) . "\r";

                        return [$role, $code, $entryRef];
                    }
                }

                $tick++;
                if ($intervalSeconds > 0) {
                    $this->sleepWatchInterval($intervalSeconds);
                }
            }
        } finally {
            if (function_exists('pcntl_signal') && $previousSigintHandler !== null) {
                pcntl_signal(SIGINT, $previousSigintHandler);
            }
        }
    }

    private function sleepWatchInterval(int $intervalSeconds): void
    {
        $deadline = time() + $intervalSeconds;
        while (!$this->watchStopRequested && time() < $deadline) {
            sleep(1);
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Resolves the developer worktree for a review entry already claimed by watch mode.
     */
    private function prepareClaimedReviewerEntry(string $reviewerCode): string
    {
        $board = $this->boardService->loadBoard($this->boardPath);
        $match = $this->reviewerSelector->findOwnedReviewingEntry($board, $reviewerCode);
        if ($match === null) {
            throw new \RuntimeException(sprintf('Reviewer %s did not keep the claimed review entry.', $reviewerCode));
        }

        $entry = $match->getEntry();
        $devCode = $entry->getDeveloper() ?? '';
        if ($devCode === '') {
            throw new \RuntimeException('Cannot determine developer agent code for the claimed review entry.');
        }

        $worktree = $this->reviewerSelector->devCodeToWorktree($devCode);
        if (!is_dir($worktree)) {
            try {
                $this->worktreeService->prepareFeatureAgentWorktree($entry);
            } catch (\RuntimeException $e) {
                $this->rollbackReviewTransition($this->buildEntryRef($entry), $reviewerCode);
                throw new \RuntimeException(sprintf(
                    'Developer WA not present at %s and could not be reconstructed: %s',
                    $worktree,
                    $e->getMessage(),
                ));
            }
        }

        $existingReviewer = $this->reviewerSelector->findExistingReviewerForWorktree($worktree);
        if ($existingReviewer !== null) {
            $this->rollbackReviewTransition($this->buildEntryRef($entry), $reviewerCode);
            throw new ActiveSessionException($existingReviewer, $this->projectRoot, $this->signaler);
        }

        return $worktree;
    }

    /**
     * Attempts to reserve one developer candidate from the current board snapshot.
     */
    private function tryWatchDeveloperClaim(BacklogBoard $board, string $developerCode): ?string
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_TODO) as $entry) {
            if ($entry->getDeveloper() !== null) {
                continue;
            }

            $entryRef = $this->boardService->computeQueuedEntryReference($entry);
            if ($this->isEntryRefOwnedByLiveDeveloperSession($board, $entryRef)) {
                continue;
            }

            try {
                $this->backlogCommandRunner->workStart($developerCode, $entryRef);

                return $entryRef;
            } catch (\RuntimeException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Attempts to reserve one reviewer candidate from the current board snapshot.
     */
    private function tryWatchReviewerClaim(BacklogBoard $board, string $reviewerCode): ?string
    {
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($this->boardService->getNormalizedStage($entry->getStage()) !== BacklogBoard::STAGE_PENDING_REVIEW) {
                continue;
            }
            if ($entry->getReviewer() !== null) {
                continue;
            }

            $entryRef = $this->buildEntryRef($entry);
            $devCode = $entry->getDeveloper() ?? '';
            if ($devCode !== '' && $this->isWorktreeUsedByLiveReviewerSession($this->reviewerSelector->devCodeToWorktree($devCode))) {
                continue;
            }

            try {
                $this->backlogCommandRunner->reviewNext($reviewerCode, $entryRef);

                return $entryRef;
            } catch (\RuntimeException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Returns true when a live developer session is already tied to this entry ref.
     */
    private function isEntryRefOwnedByLiveDeveloperSession(BacklogBoard $board, string $entryRef): bool
    {
        $agentCodes = [];

        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $entry) {
            if ($this->buildEntryRef($entry) !== $entryRef) {
                continue;
            }
            if ($entry->getDeveloper() !== null) {
                $agentCodes[] = $entry->getDeveloper();
            }
        }

        foreach (array_unique($agentCodes) as $agentCode) {
            $session = $this->sessionService->get($agentCode);
            if ($session !== null && $this->sessionDriver->isAlive($session)) {
                return true;
            }
            if ($this->sessionDriver->sessionExists($agentCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when a live reviewer session already targets this developer worktree.
     */
    private function isWorktreeUsedByLiveReviewerSession(string $worktree): bool
    {
        foreach ($this->sessionService->load() as $session) {
            if ($session->role !== AgentRole::REVIEWER || $session->worktree !== $worktree) {
                continue;
            }
            if ($this->sessionDriver->isAlive($session) || $this->sessionDriver->sessionExists($session->code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string|bool|array<bool|string>> $options
     */
    private function resolveWatchInterval(array $options): int
    {
        $raw = $this->getSingleOption($options, BacklogCliOption::WATCH_INTERVAL->value);
        if ($raw === null) {
            return 2;
        }
        if (!preg_match('/^\d+$/', $raw)) {
            throw new \RuntimeException('--watch-interval must be a non-negative integer.');
        }

        return (int) $raw;
    }

    /**
     * @return non-empty-list<string>
     */
    private function selectSpinnerFrames(): array
    {
        $locale = strtolower((string) ($_SERVER['LC_ALL'] ?? $_SERVER['LC_CTYPE'] ?? $_SERVER['LANG'] ?? ''));
        if (str_contains($locale, 'utf-8') || str_contains($locale, 'utf8')) {
            return ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        }

        return ['|', '/', '-', '\\'];
    }

    /**
     * Re-attaches to an existing live session.
     *
     * Refuses if the PHP wrapper is still alive or the direct driver has a live client
     * process (which would start a second client instance). Reconstructs the reviewer WA
     * when the stored path is missing.
     */
    private function handleAttach(AgentSession $existingSession, false|string $originalCwd): int
    {
        $code = $existingSession->code;
        $client = $existingSession->client;
        $role = $existingSession->role;

        // Refuse when the wrapper is still alive or the driver cannot safely re-attach.
        $isAlive = $this->sessionDriver->isAlive($existingSession);
        $wrapperAlive = $this->signaler->isAlive($existingSession->pid);
        if ($isAlive && ($wrapperAlive || !$this->sessionDriver->allowsResumeWhileAlive())) {
            throw new \RuntimeException(sprintf(
                "Session %s is still running (a tracked process is alive). Stop it first:\n" .
                "  php scripts/backlog-agent.php stop --code=%s",
                $code,
                $code,
            ));
        }

        $this->sessionService->updateLastSeen($code);

        $worktree = $existingSession->worktree;

        if (!is_dir($worktree)) {
            if ($role === AgentRole::REVIEWER) {
                $worktree = $this->reconstructReviewerWorktree($code, $worktree);
                if ($worktree !== $existingSession->worktree) {
                    $this->sessionService->updateWorktree($code, $worktree);
                }
            } else {
                throw new \RuntimeException(sprintf("Worktree not found for code '%s' at %s.", $code, $worktree));
            }
        }

        $launcher = $this->registry->get($client);

        if (!$launcher->isAvailable()) {
            throw new ClientNotInstalledException($client);
        }

        $contextFilePath = $this->contextBuilder->build($worktree, $code, $role);

        $launcher->prepareWorktree($worktree, $contextFilePath);

        [$bin, $binArgs] = $launcher->buildLaunchCommand($worktree, $contextFilePath, $role, null, true);

        $baseEnv = $this->buildBaseEnv($code, $role, $client);
        $env = $launcher->buildEnvironment($baseEnv, $contextFilePath);

        $this->sessionService->create($code, $client, $role, (int) getmypid(), $worktree);

        chdir($worktree);

        $sessionSvc = $this->sessionService;
        $driverName = $this->sessionDriver->driverName();
        try {
            $exitCode = $this->sessionDriver->resume(
                $code,
                $role,
                $client,
                $bin,
                $binArgs,
                $worktree,
                $env,
                static function (int $clientPid, ?string $tmuxSession) use ($sessionSvc, $code, $role, $client, $driverName, $bin, $binArgs): void {
                    $sessionSvc->updateClientPid($code, $clientPid > 0 ? $clientPid : null);
                    if ($tmuxSession !== null) {
                        $sessionSvc->updateTmuxSession($code, $tmuxSession);
                    }
                    if ($clientPid > 0) {
                        $sessionSvc->logLaunch($code, $role, $client, $driverName, $bin, $binArgs, $clientPid);
                    }
                },
            );
        } finally {
            if ($originalCwd !== false) {
                chdir($originalCwd);
            }
        }

        $capturedId = $launcher->captureCurrentSessionId($worktree);
        if ($capturedId !== null) {
            $this->sessionService->updateSessionId($code, $capturedId);
        }

        $session = $this->sessionService->get($code);
        if ($session !== null && $this->sessionDriver->isAlive($session)) {
            echo sprintf("Session detached. Use 'start --code=%s' to reconnect.\n", $code);
            return 0;
        }

        $this->sessionService->remove($code);

        return $exitCode;
    }

    /**
     * Attempts to reconstruct the developer WA for a reviewer session whose stored worktree is missing.
     *
     * @throws \RuntimeException when reconstruction is impossible
     */
    private function reconstructReviewerWorktree(string $reviewerCode, string $missingWorktree): string
    {
        if (!is_file($this->boardPath)) {
            throw new \RuntimeException(sprintf(
                'Developer WA not present at %s and could not be reconstructed: backlog board not found.',
                $missingWorktree,
            ));
        }

        try {
            $board = $this->boardService->loadBoard($this->boardPath);
            $match = $this->boardService->findReviewingEntryByReviewer($board, $reviewerCode);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(sprintf(
                'Developer WA not present at %s and could not be reconstructed: %s',
                $missingWorktree,
                $e->getMessage(),
            ));
        }

        if ($match === null) {
            throw new \RuntimeException(sprintf(
                'Developer WA not present at %s and could not be reconstructed: reviewer %s has no active reviewing entry.',
                $missingWorktree,
                $reviewerCode,
            ));
        }

        $entry = $match->getEntry();
        try {
            return $this->worktreeService->prepareFeatureAgentWorktree($entry);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(sprintf(
                'Developer WA not present at %s and could not be reconstructed: %s',
                $missingWorktree,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Resolves and prepares the reviewer session: reuses an owned reviewing entry or takes a new one.
     *
     * Returns a {@see ReviewerPickOutcome} describing what the caller should do:
     *   - normal: launch a new reviewer session on the resolved worktree
     *   - adopt: attach to an existing live reviewer session chosen by the operator
     *   - quit: the operator aborted; return 0 immediately
     *
     * For auto-select (no explicit target flag), the full list of review-stage entries is tried
     * in order. Entries whose developer WA is occupied by a live reviewer session trigger an
     * interactive conflict-resolution prompt (Accept / Pass / Quit); dead registry entries are
     * cleaned silently and the entry is retried as a normal candidate.
     *
     * @param array<string, string|bool|array<bool|string>> $options
     */
    private function prepareReviewerMode(array $options, string $reviewerCode): ReviewerPickOutcome
    {
        $force = isset($options[BacklogCliOption::FORCE->value]);
        $featureOpt = $this->getSingleOption($options, 'feature');
        $taskOpt = $this->getSingleOption($options, 'task');
        $developerOpt = $this->getSingleOption($options, 'developer');

        $board = $this->boardService->loadBoard($this->boardPath);

        // Step 1: reuse any reviewing entry already owned by this reviewer
        $ownedMatch = $this->reviewerSelector->findOwnedReviewingEntry($board, $reviewerCode);
        if ($ownedMatch !== null) {
            $entry = $ownedMatch->getEntry();
            $devCode = $entry->getDeveloper() ?? '';
            if ($devCode === '') {
                throw new \RuntimeException(
                    'Cannot determine developer agent code for the owned reviewing entry. The entry may be unassigned.',
                );
            }
            $worktree = $this->reviewerSelector->devCodeToWorktree($devCode);
            $takenEntryRef = null;
            $takenReviewerCode = null;
        } elseif ($featureOpt !== null || $taskOpt !== null || $developerOpt !== null) {
            // Step 2a: explicit targeting — select and claim once, no retry
            if ($featureOpt !== null) {
                $match = $this->reviewerSelector->selectByFeature($board, $featureOpt, $reviewerCode);
            } elseif ($taskOpt !== null) {
                $match = $this->reviewerSelector->selectByTask($board, $taskOpt, $reviewerCode);
            } elseif ($developerOpt !== null) {
                $match = $this->reviewerSelector->selectByDeveloper($board, $developerOpt, $reviewerCode);
            } else {
                throw new \LogicException('Unexpected state: no explicit targeting option is set.');
            }

            $entry = $match->getEntry();
            $devCode = $entry->getDeveloper() ?? '';

            if ($devCode === '') {
                throw new \RuntimeException(
                    'Cannot determine developer agent code for the selected entry. The entry may be unassigned.',
                );
            }

            $worktree = $this->reviewerSelector->devCodeToWorktree($devCode);

            // Delegate the review transition to backlog.php under the global mutation lock.
            // Only applies when the entry is in review stage; an already-reviewing entry
            // means the reviewer owns it via explicit targeting after step 1 missed it.
            if ($this->boardService->getNormalizedStage($entry->getStage()) === BacklogBoard::STAGE_PENDING_REVIEW) {
                $entryRef = $this->buildEntryRef($entry);
                $this->backlogCommandRunner->reviewNext($reviewerCode, $entryRef);
                $takenEntryRef = $entryRef;
                $takenReviewerCode = $reviewerCode;
                // Reload board so subsequent steps (e.g. prepareFeatureAgentWorktree) use the entry at
                // its updated stage, consistent with the spec requirement.
                $board = $this->boardService->loadBoard($this->boardPath);
                $reloadedMatch = $this->reviewerSelector->findOwnedReviewingEntry($board, $reviewerCode);
                if ($reloadedMatch !== null) {
                    $entry = $reloadedMatch->getEntry();
                }
            } else {
                $takenEntryRef = null;
                $takenReviewerCode = null;
            }
        } else {
            // Step 2b: auto-select with interactive conflict resolution for occupied WAs.
            $pickOutcome = $this->pickInteractiveReviewer($board, $reviewerCode);

            if ($pickOutcome === null) {
                throw new \RuntimeException(
                    'No review available. All review-stage entries are already being reviewed or no entry is ready for review.',
                );
            }

            if ($pickOutcome->isQuit()) {
                return ReviewerPickOutcome::quit();
            }

            if ($pickOutcome->isAdopt()) {
                return $pickOutcome;
            }

            // Normal taken path: worktree and entryRef are already in the outcome.
            $worktree = $pickOutcome->getWorktree();
            $takenEntryRef = $pickOutcome->getTakenEntryRef();
            $takenReviewerCode = $reviewerCode;

            // Reload board so subsequent steps use the entry at its updated stage.
            $board = $this->boardService->loadBoard($this->boardPath);
            $reloadedMatch = $this->reviewerSelector->findOwnedReviewingEntry($board, $reviewerCode);
            if ($reloadedMatch !== null) {
                $entry = $reloadedMatch->getEntry();
            } elseif ($pickOutcome->getTakenMatch() !== null) {
                // Fallback: use the pre-reload entry (e.g. timing edge case after review-next).
                $entry = $pickOutcome->getTakenMatch()->getEntry();
            } else {
                throw new \RuntimeException(
                    'Could not find the reviewing entry after reloading the board.',
                );
            }
        }

        // Validate or reconstruct the developer WA
        if (!is_dir($worktree)) {
            try {
                $this->worktreeService->prepareFeatureAgentWorktree($entry);
            } catch (\RuntimeException $e) {
                $this->rollbackReviewTransition($takenEntryRef, $takenReviewerCode);
                throw new \RuntimeException(sprintf(
                    'Developer WA not present at %s and could not be reconstructed: %s',
                    $worktree,
                    $e->getMessage(),
                ));
            }
        }

        $existingReviewer = $this->reviewerSelector->findExistingReviewerForWorktree($worktree);
        if ($existingReviewer !== null && !$force) {
            $this->rollbackReviewTransition($takenEntryRef, $takenReviewerCode);
            throw new ActiveSessionException($existingReviewer, $this->projectRoot, $this->signaler);
        }

        return ReviewerPickOutcome::normal($worktree, $takenEntryRef, $takenReviewerCode);
    }

    /**
     * Auto-picker with interactive conflict resolution for occupied developer WAs.
     *
     * Iterates all review-stage candidates in board order. For each candidate:
     *   - No existing reviewer session → try review-next; contention (EntryNotReservable) is silently skipped.
     *   - Dead registry entry → clean it from the registry and retry that entry as a normal candidate.
     *   - Alive + tmux attached elsewhere → log and auto-pass (cannot double-attach).
     *   - Alive + tmux detached → prompt the operator (Accept / Pass / Quit).
     *
     * Returns null when all candidates are exhausted without a successful pick.
     * Returns a quit outcome when the operator chose to abort.
     * Returns an adopt outcome when the operator accepted an existing session.
     * Returns a normal taken outcome when an entry was successfully claimed.
     */
    private function pickInteractiveReviewer(BacklogBoard $board, string $reviewerCode): ?ReviewerPickOutcome
    {
        // Pre-collect candidates so we can compute hasMoreCandidates accurately.
        $candidates = [];
        foreach ($board->getEntries(BacklogBoard::SECTION_ACTIVE) as $index => $entry) {
            if ($this->boardService->getNormalizedStage($entry->getStage()) !== BacklogBoard::STAGE_PENDING_REVIEW) {
                continue;
            }
            $devCode = $entry->getDeveloper() ?? '';
            if ($devCode === '') {
                continue;
            }
            $candidates[] = [$index, $entry];
        }

        $count = count($candidates);
        for ($i = 0; $i < $count; $i++) {
            [$index, $entry] = $candidates[$i];
            $devCode = $entry->getDeveloper() ?? '';
            $worktree = $this->reviewerSelector->devCodeToWorktree($devCode);
            $existingReviewer = $this->reviewerSelector->findExistingReviewerForWorktree($worktree);

            if ($existingReviewer === null) {
                // Normal case: no occupant — try to claim the entry.
                $entryRef = $this->buildEntryRef($entry);
                try {
                    $this->backlogCommandRunner->reviewNext($reviewerCode, $entryRef);

                    return ReviewerPickOutcome::normalWithMatch(
                        $worktree,
                        $entryRef,
                        $reviewerCode,
                        new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry),
                    );
                } catch (EntryNotReservableException) {
                    continue;
                }
            }

            // WA is occupied by an existing reviewer session — check liveness.
            $isAlive = $this->sessionDriver->isAlive($existingReviewer);

            if (!$isAlive) {
                // Dead session: remove from registry and retry this entry as a fresh candidate.
                echo sprintf(
                    "Reviewer session %s in the registry is no longer alive. Cleaning up registry entry...\n",
                    $existingReviewer->code,
                );
                $this->sessionService->remove($existingReviewer->code);

                $entryRef = $this->buildEntryRef($entry);
                try {
                    $this->backlogCommandRunner->reviewNext($reviewerCode, $entryRef);

                    return ReviewerPickOutcome::normalWithMatch(
                        $worktree,
                        $entryRef,
                        $reviewerCode,
                        new BoardEntryMatch(BacklogBoard::SECTION_ACTIVE, $index, $entry),
                    );
                } catch (EntryNotReservableException) {
                    continue;
                }
            }

            // Session is alive — check whether a terminal is already attached to it.
            $isAttached = $this->sessionDriver->isAttached($existingReviewer);

            if ($isAttached) {
                // Auto-pass: the session is attached elsewhere; double-attaching is forbidden.
                echo sprintf(
                    "Reviewer session %s is already attached to another terminal. Skipping entry '%s'.\n",
                    $existingReviewer->code,
                    $this->buildEntryRef($entry),
                );
                continue;
            }

            // Session is alive and detached: prompt the operator.
            $hasMoreCandidates = $i < $count - 1;
            $choice = $this->resolveWaConflict($existingReviewer, $entry, $hasMoreCandidates);

            if ($choice === WaOccupantChoice::Pass) {
                continue;
            }

            if ($choice === WaOccupantChoice::Quit) {
                return ReviewerPickOutcome::quit();
            }

            // Accept: assign the entry to the existing reviewer session via review-next.
            $entryRef = $this->buildEntryRef($entry);
            $this->backlogCommandRunner->reviewNext($existingReviewer->code, $entryRef);

            return ReviewerPickOutcome::adopt($existingReviewer, $entryRef, $existingReviewer->code);
        }

        return null;
    }

    /**
     * Resolves the operator's choice when a developer WA is occupied by a live detached reviewer session.
     *
     * Delegates to the injected $waConflictPrompter when set (for tests); otherwise reads from stdin.
     *
     * @param bool $hasMoreCandidates True when at least one more review-stage entry exists after this one
     */
    private function resolveWaConflict(AgentSession $existing, BoardEntry $entry, bool $hasMoreCandidates): WaOccupantChoice
    {
        if ($this->waConflictPrompter !== null) {
            return ($this->waConflictPrompter)($existing, $entry, $hasMoreCandidates);
        }

        return $this->promptWaConflict($existing, $entry, $hasMoreCandidates);
    }

    /**
     * Displays the WA-occupant conflict prompt on stdout and reads the operator's choice from stdin.
     *
     * @param bool $hasMoreCandidates True when at least one more review-stage entry exists after this one
     */
    private function promptWaConflict(AgentSession $existing, BoardEntry $entry, bool $hasMoreCandidates): WaOccupantChoice
    {
        $relativeWorktree = str_replace($this->projectRoot . '/', '', $existing->worktree);
        $entryRef = $this->buildEntryRef($entry);

        echo sprintf(
            "\nEntry '%s' is awaiting review, but its developer WA (%s) is already occupied\n" .
            "by a live reviewer session:\n" .
            "  code       : %s\n" .
            "  client     : %s\n" .
            "  started_at : %s\n" .
            "  last_seen  : %s\n\n",
            $entryRef,
            $relativeWorktree,
            $existing->code,
            $existing->client->value,
            $existing->startedAt->format(\DateTimeInterface::ATOM),
            $existing->lastSeenAt->format(\DateTimeInterface::ATOM),
        );

        $options = ['A' => WaOccupantChoice::Accept, 'Q' => WaOccupantChoice::Quit];
        $labels = ['A' => 'Accept (attach to the existing session and assign this entry to it)'];

        if ($hasMoreCandidates) {
            $options['P'] = WaOccupantChoice::Pass;
            $labels['P'] = 'Pass (skip this entry and continue to the next one)';
        }

        $labels['Q'] = 'Quit (abort the picker; no entry is claimed)';

        foreach ($labels as $key => $label) {
            echo sprintf("  [%s] %s\n", $key, $label);
        }

        $prompt = sprintf("\nChoice [%s]: ", implode('/', array_keys($options)));

        while (true) {
            echo $prompt;
            $line = fgets(STDIN);
            if ($line === false) {
                return WaOccupantChoice::Quit;
            }
            $upper = strtoupper(trim($line));
            if (isset($options[$upper])) {
                return $options[$upper];
            }
        }
    }

    /**
     * Rolls back a review transition from reviewing → review on best-effort basis.
     *
     * Delegates to review-cancel via BacklogCommandRunner so the release goes through
     * the same lock and revalidation path as any other backlog mutation.
     * Accepts nullable parameters so callers can pass tracked state directly.
     */
    private function rollbackReviewTransition(?string $entryRef, ?string $reviewerCode): void
    {
        if ($entryRef === null || $reviewerCode === null) {
            return;
        }

        try {
            $this->backlogCommandRunner->reviewCancel($reviewerCode, $entryRef);
        } catch (\Exception) {
            // best-effort rollback; caller's original exception takes priority
        }
    }

    /**
     * Prepares the developer session: auto-picks the first available queued task when the developer
     * has no active entry, or resumes silently when one is already in progress.
     *
     * Iterates the full todo list via {@see AgentDeveloperSelector::pick()} so that a concurrent
     * agent claiming the head entry does not abort this launch — it simply moves to the next
     * candidate. Throws when the todo list is entirely exhausted (no entry could be reserved).
     *
     * Returns [$entryRef, $developerCode] when a task was taken (for rollback on failure),
     * or [null, null] when the developer already has an active entry (resume case — no task taken).
     *
     * @return array{0: string|null, 1: string|null}
     * @throws \RuntimeException when the todo queue is empty or all entries are unavailable
     */
    private function prepareDeveloperMode(string $developerCode): array
    {
        $board = $this->boardService->loadBoard($this->boardPath);

        if ($this->developerSelector->findOwnedActiveEntry($board, $developerCode) !== null) {
            return [null, null];
        }

        $entryRef = $this->developerSelector->pick($board, $developerCode, $this->backlogCommandRunner);
        if ($entryRef === null) {
            throw new \RuntimeException(sprintf('No queued task available for %s.', $developerCode));
        }

        return [$entryRef, $developerCode];
    }

    /**
     * Rolls back a start transition on best-effort basis via release.
     *
     * Delegates to release via BacklogCommandRunner so the release goes through
     * the same lock and revalidation path as any other backlog mutation.
     * Accepts nullable parameters so callers can pass tracked state directly.
     */
    private function rollbackWorkStart(?string $entryRef, ?string $developerCode): void
    {
        if ($entryRef === null || $developerCode === null) {
            return;
        }

        try {
            $this->backlogCommandRunner->entryRelease($developerCode, $entryRef);
        } catch (\Exception) {
            // best-effort rollback; caller's original exception takes priority
        }
    }

    /**
     * Returns the stable entry reference for use in backlog commands (<feature> or <feature>/<task>).
     */
    private function buildEntryRef(BoardEntry $entry): string
    {
        if ($this->boardService->checkIsTaskEntry($entry)) {
            return $this->boardService->getTaskReviewKey($entry);
        }

        $feature = $entry->getFeature();
        if ($feature === null || $feature === '') {
            throw new \RuntimeException('Entry has no feature slug; cannot build entry reference.');
        }

        return $feature;
    }

    /**
     * Resolves the role from parsed options; exactly one role flag must be present.
     *
     * --developer=<dXX> (with value) is a reviewer targeting flag, not a role flag.
     *
     * @param array<string, string|bool|array<bool|string>> $options
     */
    private function resolveRole(array $options, bool $allowMissing = false): ?AgentRole
    {
        $isDeveloper = ($options[BacklogCliOption::DEVELOPER->value] ?? null) === true;
        $isReviewer = isset($options[BacklogCliOption::REVIEWER->value]);
        $isManager = isset($options[BacklogCliOption::MANAGER->value]);

        $count = (int) $isDeveloper + (int) $isReviewer + (int) $isManager;
        if ($count === 0) {
            if ($allowMissing) {
                return null;
            }
            throw new \RuntimeException('Exactly one of --developer, --reviewer, or --manager is required.');
        }
        if ($count > 1) {
            throw new \RuntimeException('Only one of --developer, --reviewer, or --manager may be specified.');
        }

        return match (true) {
            $isDeveloper => AgentRole::DEVELOPER,
            $isReviewer => AgentRole::REVIEWER,
            default => AgentRole::MANAGER,
        };
    }

    /**
     * Returns the base environment array for the CLI process.
     *
     * @return array<string, string>
     */
    private function buildBaseEnv(string $code, AgentRole $role, AgentClient $client): array
    {
        $inherited = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $inherited[$key] = $value;
            }
        }

        return array_merge($inherited, [
            'SOMANAGER_AGENT' => $code,
            'SOMANAGER_ROLE' => $role->value,
            'SOMANAGER_CLIENT' => $client->value,
            'SOMANAGER_WP' => $this->projectRoot,
        ]);
    }

    /**
     * Returns true when the given worktree has uncommitted changes.
     */
    private function hasLocalChanges(string $worktree): bool
    {
        $output = $this->shellRunner->output('git status --porcelain', $worktree);

        return $output !== null && trim($output) !== '';
    }
}
