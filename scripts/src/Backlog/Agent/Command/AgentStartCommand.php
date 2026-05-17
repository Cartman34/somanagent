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
use SoManAgent\Script\Backlog\Agent\Service\AgentCodeService;
use SoManAgent\Script\Backlog\Agent\Service\AgentContextBuilder;
use SoManAgent\Script\Backlog\Agent\Service\AgentDeveloperSelector;
use SoManAgent\Script\Backlog\Agent\Service\AgentLaunchPromptResolver;
use SoManAgent\Script\Backlog\Agent\Service\AgentModelResolver;
use SoManAgent\Script\Backlog\Agent\Service\AgentReviewerSelector;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Model\BoardEntry;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;

/**
 * Starts an AI agent session in a dedicated worktree.
 *
 * Usage:
 *   php scripts/backlog-agent.php start <client> --developer [--code=<dXX>] [--reset]
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
     * @param ProcessSignaler $signaler Used for ActiveSessionException liveness check
     * @param ProcessRunner $shellRunner Used to check for local changes in the worktree
     * @param BacklogCommandRunner $backlogCommandRunner Used to delegate review-next, review-cancel, work-start, and entry-release under the backlog lock
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
        $this->modelResolver = $modelResolver;
        $this->launchPromptResolver = $launchPromptResolver ?? new AgentLaunchPromptResolver(
            dirname(__DIR__, 4) . '/resources/backlog-agent/launch-prompts.yaml',
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Start an AI agent session in a dedicated worktree';
    }

    /**
     * {@inheritdoc}
     */
    public function getArguments(): array
    {
        return [
            ['name' => '<client>', 'description' => 'AI client to launch: claude, codex, opencode, gemini'],
        ];
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
            ['name' => '--feature=<slug>', 'description' => 'Reviewer: target the feature entry at stage=review with this slug'],
            ['name' => '--task=<feature/task>', 'description' => 'Reviewer: target the task entry at stage=review with this reference'],
            ['name' => '--developer=<dXX>', 'description' => 'Reviewer: target the active entry assigned to this developer code'],
            ['name' => '--force', 'description' => 'Reviewer: proceed even when another reviewer session is active in the target WA'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getUsageExamples(): array
    {
        return [
            'php scripts/backlog-agent.php start claude --developer',
            'php scripts/backlog-agent.php start claude --developer --code=d04',
            'php scripts/backlog-agent.php start codex --developer --tier=economy --effort=low',
            'php scripts/backlog-agent.php start gemini --manager --model=gemini-2.5-pro',
            'php scripts/backlog-agent.php start claude --reviewer',
            'php scripts/backlog-agent.php start claude --reviewer --feature=my-feature',
            'php scripts/backlog-agent.php start claude --reviewer --task=my-feature/my-task',
            'php scripts/backlog-agent.php start claude --reviewer --developer=d04',
            'php scripts/backlog-agent.php start claude --reviewer --developer=d04 --force',
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
        $reset = isset($options['reset']);
        $tierOverride = $this->getSingleOption($options, 'tier');
        $effortOverride = $this->getSingleOption($options, 'effort');
        $modelOverride = $this->getSingleOption($options, 'model');

        if ($reset && $role !== AgentRole::DEVELOPER) {
            throw new \RuntimeException('--reset is only allowed with --developer.');
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
            if ($takenEntryRef !== null) {
                $initialPrompt = $this->launchPromptResolver->resolve(AgentRole::REVIEWER);
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
            [$takenWorkStartRef, $takenWorkStartDevCode] = $this->prepareDeveloperMode($code);
            if ($takenWorkStartRef !== null) {
                $initialPrompt = $this->launchPromptResolver->resolve(AgentRole::DEVELOPER);
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
        try {
            $exitCode = $this->sessionDriver->launch(
                $code,
                $bin,
                $binArgs,
                $worktree,
                $env,
                static function (int $clientPid, ?string $tmuxSession) use ($sessionSvc, $code): void {
                    $sessionSvc->updateClientPid($code, $clientPid > 0 ? $clientPid : null);
                    if ($tmuxSession !== null) {
                        $sessionSvc->updateTmuxSession($code, $tmuxSession);
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
            echo sprintf("Session detached. Use 'resume --code=%s' to reconnect.\n", $code);
            return 0;
        }

        $this->sessionService->remove($code);

        return $exitCode;
    }

    /**
     * Resolves and prepares the reviewer session: reuses an owned reviewing entry or takes a new one.
     *
     * When a new entry is taken via review-next, [1] holds the entry ref and [2] the reviewer
     * code so that rollbackReviewTransition() can release it via review-cancel on failure.
     * Both are null when an already-reviewing entry is reused (no rollback needed).
     *
     * @param array<string, string|bool|array<bool|string>> $options
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function prepareReviewerMode(array $options, string $reviewerCode): array
    {
        $force = isset($options['force']);
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
        } else {
            // Step 2+: select an entry to take
            if ($featureOpt !== null) {
                $match = $this->reviewerSelector->selectByFeature($board, $featureOpt, $reviewerCode);
            } elseif ($taskOpt !== null) {
                $match = $this->reviewerSelector->selectByTask($board, $taskOpt, $reviewerCode);
            } elseif ($developerOpt !== null) {
                $match = $this->reviewerSelector->selectByDeveloper($board, $developerOpt, $reviewerCode);
            } else {
                $match = $this->reviewerSelector->autoSelect($board);
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
     * Prepares the developer session: auto-picks the first queued task when the developer
     * has no active entry, or resumes silently when one is already in progress.
     *
     * Returns [$entryRef, $developerCode] when a task was taken (for rollback on failure),
     * or [null, null] when resuming an existing entry (no rollback needed).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function prepareDeveloperMode(string $developerCode): array
    {
        $board = $this->boardService->loadBoard($this->boardPath);

        if ($this->developerSelector->findOwnedActiveEntry($board, $developerCode) !== null) {
            return [null, null];
        }

        $entryRef = $this->developerSelector->selectFirstQueued($board, $developerCode);
        $this->backlogCommandRunner->workStart($developerCode, $entryRef);

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
        $isDeveloper = ($options['developer'] ?? null) === true;
        $isReviewer = isset($options['reviewer']);
        $isManager = isset($options['manager']);

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
