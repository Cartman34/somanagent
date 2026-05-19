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
use SoManAgent\Script\Backlog\Agent\Exception\ActiveSessionException;
use SoManAgent\Script\Backlog\Agent\Exception\ClientNotInstalledException;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
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
     * @param BacklogCommandRunner $backlogCommandRunner Used to delegate review-next, review-cancel, work-start, and entry-release under the backlog lock
     * @param EntryRebaseService $entryRebaseService Handles approved-entry rebase before developer session launch; use NullEntryRebaseService in tests that do not exercise the approved path
     * @param AgentModelResolver|null $modelResolver Resolves role/client model tier and effort into CLI args
     * @param AgentLaunchPromptResolver|null $launchPromptResolver Resolves role-specific initial prompts for auto-picked entries; defaults to the bundled scripts resource
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

        $role = $this->resolveRole($options);
        $reset = isset($options[BacklogCliOption::RESET->value]);
        $forceNew = isset($options[BacklogCliOption::FORCE_NEW->value]);
        $tierOverride = $this->getSingleOption($options, 'tier');
        $effortOverride = $this->getSingleOption($options, 'effort');
        $modelOverride = $this->getSingleOption($options, 'model');

        if ($reset && $role !== AgentRole::DEVELOPER) {
            throw new \RuntimeException('--reset is only allowed with --developer.');
        }

        if ($forceNew && $role !== AgentRole::DEVELOPER) {
            throw new \RuntimeException('--force-new is only allowed with --developer.');
        }

        $resolvedModel = $this->modelResolver?->resolve($client, $role, $tierOverride, $effortOverride, $modelOverride);
        if ($resolvedModel !== null) {
            foreach ($resolvedModel->warnings as $warning) {
                echo "Warning: {$warning}\n";
            }
        }

        // Validate driver dependencies (e.g. tmux binary) before any worktree work.
        $this->sessionDriver->checkDependencies();

        $codeOption = $this->getSingleOption($options, 'code');

        if ($codeOption !== null) {
            $this->codeService->validate($codeOption, $role);
            $code = $codeOption;
        } else {
            $code = $this->codeService->allocateForRole($role);
        }

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

        $launcher = $this->registry->get($client);

        if (!$launcher->isAvailable()) {
            throw new ClientNotInstalledException($client);
        }

        $takenEntryRef = null;
        $takenReviewerCode = null;
        $takenWorkStartRef = null;
        $takenWorkStartDevCode = null;
        $initialPrompt = null;

        if ($role === AgentRole::REVIEWER) {
            [$worktree, $takenEntryRef, $takenReviewerCode] = $this->prepareReviewerMode($options, $code);
            $reviewerStage = $takenEntryRef !== null ? BacklogBoard::STAGE_IN_REVIEW : BacklogBoard::STAGE_REVIEWING;
            $initialPrompt = $this->launchPromptResolver->resolveStageDecision(AgentRole::REVIEWER, $reviewerStage)->getPrompt();
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
            [$takenWorkStartRef, $takenWorkStartDevCode] = $this->prepareDeveloperMode($code);

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
     * When a new entry is taken via review-next, [1] holds the entry ref and [2] the reviewer
     * code so that rollbackReviewTransition() can release it via review-cancel on failure.
     * Both are null when an already-reviewing entry is reused (no rollback needed).
     *
     * For auto-select (no explicit target flag), the full list of review-stage entries is tried
     * in order via {@see AgentReviewerSelector::pick()} so that a concurrent reviewer claiming
     * the head entry does not abort this launch — it simply moves to the next candidate.
     *
     * @param array<string, string|bool|array<bool|string>> $options
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function prepareReviewerMode(array $options, string $reviewerCode): array
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
            $devCode = $entry->getAgent() ?? '';
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
            $devCode = $entry->getAgent() ?? '';

            if ($devCode === '') {
                throw new \RuntimeException(
                    'Cannot determine developer agent code for the selected entry. The entry may be unassigned.',
                );
            }

            $worktree = $this->reviewerSelector->devCodeToWorktree($devCode);

            // Delegate the review transition to backlog.php under the global mutation lock.
            // Only applies when the entry is in review stage; an already-reviewing entry
            // means the reviewer owns it via explicit targeting after step 1 missed it.
            if ($this->boardService->getNormalizedStage($entry->getStage()) === BacklogBoard::STAGE_IN_REVIEW) {
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
            // Step 2b: auto-select — iterate all candidates with retry on contention
            $match = $this->reviewerSelector->pick($board, $reviewerCode, $this->backlogCommandRunner);
            if ($match === null) {
                throw new \RuntimeException(
                    'No review available. All review-stage entries are already being reviewed or no entry is ready for review.',
                );
            }

            $entry = $match->getEntry();
            $devCode = $entry->getAgent() ?? '';

            if ($devCode === '') {
                throw new \RuntimeException(
                    'Cannot determine developer agent code for the selected entry. The entry may be unassigned.',
                );
            }

            $worktree = $this->reviewerSelector->devCodeToWorktree($devCode);
            $takenEntryRef = $this->buildEntryRef($entry);
            $takenReviewerCode = $reviewerCode;

            // Reload board so subsequent steps use the entry at its updated stage.
            $board = $this->boardService->loadBoard($this->boardPath);
            $reloadedMatch = $this->reviewerSelector->findOwnedReviewingEntry($board, $reviewerCode);
            if ($reloadedMatch !== null) {
                $entry = $reloadedMatch->getEntry();
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

        return [$worktree, $takenEntryRef, $takenReviewerCode];
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
     * Rolls back a work-start transition on best-effort basis via entry-release.
     *
     * Delegates to entry-release via BacklogCommandRunner so the release goes through
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
    private function resolveRole(array $options): AgentRole
    {
        $isDeveloper = ($options[BacklogCliOption::DEVELOPER->value] ?? null) === true;
        $isReviewer = isset($options[BacklogCliOption::REVIEWER->value]);
        $isManager = isset($options[BacklogCliOption::MANAGER->value]);

        $count = (int) $isDeveloper + (int) $isReviewer + (int) $isManager;
        if ($count === 0) {
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
