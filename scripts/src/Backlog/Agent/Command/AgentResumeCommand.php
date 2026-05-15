<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Command;

use SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncherRegistry;
use SoManAgent\Script\Backlog\Agent\Client\SessionDriverInterface;
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
    private SessionDriverInterface $sessionDriver;

    /**
     * @param string $projectRoot
     * @param AgentClientLauncherRegistry $registry
     * @param AgentContextBuilder $contextBuilder
     * @param AgentSessionService $sessionService
     * @param BacklogBoardService $boardService
     * @param BacklogWorktreeService $worktreeService
     * @param string $boardPath
     * @param SessionDriverInterface $sessionDriver
     */
    public function __construct(
        string $projectRoot,
        AgentClientLauncherRegistry $registry,
        AgentContextBuilder $contextBuilder,
        AgentSessionService $sessionService,
        BacklogBoardService $boardService,
        BacklogWorktreeService $worktreeService,
        string $boardPath,
        SessionDriverInterface $sessionDriver,
    ) {
        $this->projectRoot = $projectRoot;
        $this->registry = $registry;
        $this->contextBuilder = $contextBuilder;
        $this->sessionService = $sessionService;
        $this->boardService = $boardService;
        $this->worktreeService = $worktreeService;
        $this->boardPath = $boardPath;
        $this->sessionDriver = $sessionDriver;
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

        if ($this->sessionDriver->isAlive($existingSession)) {
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

        [$bin, $binArgs] = $launcher->buildLaunchCommand($worktree, $contextFilePath, $role, $sessionId, $continueLast);

        $baseEnv = $this->buildBaseEnv($code, $role, $client);
        $env = $launcher->buildEnvironment($baseEnv, $contextFilePath);

        $this->sessionService->create($code, $client, $role, (int) getmypid(), $worktree);

        chdir($worktree);

        $sessionSvc = $this->sessionService;
        $exitCode = $this->sessionDriver->resume(
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

        $capturedId = $launcher->captureCurrentSessionId($worktree);
        if ($capturedId !== null) {
            $this->sessionService->updateSessionId($code, $capturedId);
        }

        if ($this->sessionDriver->sessionExists($code)) {
            echo sprintf("Session détachée. Utilisez resume --code=%s pour vous rattacher.\n", $code);
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
