<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Command;

use SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncherRegistry;
use SoManAgent\Script\Backlog\Agent\Client\InteractiveProcessRunner;
use SoManAgent\Script\Backlog\Agent\Client\ProcessSignaler;
use SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use SoManAgent\Script\Backlog\Agent\Enum\AgentRole;
use SoManAgent\Script\Backlog\Agent\Exception\ClientNotInstalledException;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Backlog\Agent\Service\AgentContextBuilder;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Backlog\Service\BacklogWorktreeService;

/**
 * Re-launches the CLI for an interrupted session, reconnecting to the previous CLI conversation.
 *
 * Usage:
 *   php scripts/backlog-agent.php resume --code=<code> [--session=<id>]
 */
final class AgentResumeCommand extends AbstractAgentCommand
{
    private string $projectRoot;
    private AgentClientLauncherRegistry $registry;
    private AgentContextBuilder $contextBuilder;
    private AgentSessionService $sessionService;
    private BacklogBoardService $boardService;
    private BacklogWorktreeService $worktreeService;
    private string $boardPath;
    private InteractiveProcessRunner $processRunner;
    private ProcessSignaler $signaler;

    /**
     * @param string $projectRoot
     * @param AgentClientLauncherRegistry $registry
     * @param AgentContextBuilder $contextBuilder
     * @param AgentSessionService $sessionService
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     * @param string $boardPath
     * @param InteractiveProcessRunner $processRunner
     * @param ProcessSignaler $signaler
     */
    public function __construct(
        string $projectRoot,
        AgentClientLauncherRegistry $registry,
        AgentContextBuilder $contextBuilder,
        AgentSessionService $sessionService,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        string $boardPath,
        InteractiveProcessRunner $processRunner,
        ProcessSignaler $signaler,
    ) {
        $this->projectRoot = $projectRoot;
        $this->registry = $registry;
        $this->contextBuilder = $contextBuilder;
        $this->sessionService = $sessionService;
        $this->boardService = $boardService;
        $this->worktreeService = $worktreeService;
        $this->boardPath = $boardPath;
        $this->processRunner = $processRunner;
        $this->signaler = $signaler;
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Re-launch the CLI for an interrupted session, reconnecting to the previous CLI conversation';
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions(): array
    {
        return [
            ['name' => '--code=<code>', 'description' => 'Agent code of the interrupted session (required). Client and role are derived from the sessions.json entry.'],
            ['name' => '--session=<id>', 'description' => 'Resume a specific CLI session ID belonging to the code\'s WA. Omit to continue the last session.'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getUsageExamples(): array
    {
        return [
            'php scripts/backlog-agent.php resume --code=d04',
            'php scripts/backlog-agent.php resume --code=d04 --session=abc-uuid',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $args, array $options): int
    {
        $code = $this->getSingleOption($options, 'code');
        if ($code === null || $code === '') {
            throw new \RuntimeException('--code=<code> is required.');
        }

        $existingSession = $this->sessionService->get($code);
        if ($existingSession === null) {
            throw new \RuntimeException(sprintf(
                "No active session found for code '%s'. Use 'start' to begin a new session.",
                $code,
            ));
        }

        $this->sessionService->updateLastSeen($code);

        if ($this->isAnyTrackedProcessAlive($existingSession)) {
            throw new \RuntimeException(sprintf(
                "Session %s is still running (a tracked process is alive). Stop it first:\n" .
                "  php scripts/backlog-agent.php stop --code=%s",
                $code,
                $code,
            ));
        }

        $client = $existingSession->client;
        $role = $existingSession->role;

        $sessionId = $this->getSingleOption($options, 'session');
        $continueLast = $sessionId === null;

        // Always use the worktree stored in sessions.json (mandatory for reviewer sessions
        // whose worktree is the shared developer WA, not .agent-worktrees/<rXX>).
        $worktree = $existingSession->worktree;

        if (!is_dir($worktree)) {
            if ($role === AgentRole::REVIEWER) {
                $worktree = $this->reconstructReviewerWorktree($code, $worktree);
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

        [$bin, $binArgs] = $launcher->buildLaunchCommand($worktree, $contextFilePath, $role, $sessionId, $continueLast);

        $baseEnv = $this->buildBaseEnv($code, $role, $client);
        $env = $launcher->buildEnvironment($baseEnv, $contextFilePath);

        $this->sessionService->create($code, $client, $role, (int) getmypid(), $worktree);

        chdir($worktree);

        $sessionService = $this->sessionService;
        $result = $this->processRunner->run(
            $bin,
            $binArgs,
            $worktree,
            $env,
            static function (int $clientPid, ?int $pgid) use ($sessionService, $code): void {
                $sessionService->updateClientProcess($code, $clientPid > 0 ? $clientPid : null, $pgid);
            },
        );

        $capturedId = $launcher->captureCurrentSessionId($worktree);
        if ($capturedId !== null) {
            $this->sessionService->updateSessionId($code, $capturedId);
        }

        $this->sessionService->remove($code);

        return $result->exitCode;
    }

    /**
     * Returns true when any process recorded in the session is still alive (client first, wrapper next).
     */
    private function isAnyTrackedProcessAlive(AgentSession $session): bool
    {
        if ($session->clientPid !== null && $session->clientPid > 0 && $this->signaler->isAlive($session->clientPid)) {
            return true;
        }

        return $session->pid > 0 && $this->signaler->isAlive($session->pid);
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
}
