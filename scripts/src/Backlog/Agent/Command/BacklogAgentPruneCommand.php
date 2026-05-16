<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Command;

use SoManAgent\Script\Backlog\Agent\Client\SessionDriverInterface;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Console;

/**
 * Removes invalid or orphan entries from local/tmp/agent-sessions.json.
 *
 * Auto-removed entries (no flag required):
 *   1. client_pid is null AND tmux_session is null (launch was never finalised — e.g. tmux pane PID lookup failed)
 *   2. the active session driver reports isAlive() = false (process gone for direct driver, tmux session missing for tmux driver)
 *   3. the recorded worktree does not exist on disk AND the process is not alive
 *
 * Warning entries (kept unless --force):
 *   - the recorded worktree does not exist on disk BUT the process is still alive (orphan WA, killing it is the operator's call)
 *
 * --dry-run previews removals without writing changes. --force also removes warning entries; the process itself
 * is not signalled, only the json entry is dropped — the operator should run `stop --code=<code>` to terminate cleanly.
 */
final class BacklogAgentPruneCommand extends AbstractAgentCommand
{
    private const ACTION_REMOVE = 'remove';
    private const ACTION_WARN = 'warn';
    private const ACTION_KEEP = 'keep';

    private Console $console;
    private AgentSessionService $sessionService;
    private SessionDriverInterface $sessionDriver;

    /**
     * @param Console $console Output stream
     * @param AgentSessionService $sessionService Reads and mutates agent-sessions.json
     * @param SessionDriverInterface $sessionDriver Used to check liveness of each session
     */
    public function __construct(
        Console $console,
        AgentSessionService $sessionService,
        SessionDriverInterface $sessionDriver,
    ) {
        $this->console = $console;
        $this->sessionService = $sessionService;
        $this->sessionDriver = $sessionDriver;
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Remove invalid or orphan entries from agent-sessions.json';
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions(): array
    {
        return [
            ['name' => '--dry-run', 'description' => 'Preview removals without writing changes'],
            ['name' => '--force', 'description' => 'Also remove warning entries (worktree gone, process still alive). The process itself is not signalled.'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getUsageExamples(): array
    {
        return [
            'php scripts/backlog-agent.php prune',
            'php scripts/backlog-agent.php prune --dry-run',
            'php scripts/backlog-agent.php prune --force',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $args, array $options): int
    {
        $dryRun = isset($options['dry-run']);
        $force = isset($options['force']);

        $sessions = $this->sessionService->load();
        ksort($sessions);

        if ($sessions === []) {
            $this->console->line('No agent sessions.');

            return 0;
        }

        $removed = 0;
        $warnings = 0;

        foreach ($sessions as $code => $session) {
            $decision = $this->decide($session);

            if ($decision['action'] === self::ACTION_KEEP) {
                continue;
            }

            if ($decision['action'] === self::ACTION_REMOVE) {
                $this->console->line(sprintf('✓ removed %s (%s)', $code, $decision['reason']));
                if (!$dryRun) {
                    $this->sessionService->remove($code);
                }
                $removed++;
                continue;
            }

            // ACTION_WARN
            if ($force) {
                $this->console->line(sprintf('✓ removed %s (%s — --force)', $code, $decision['reason']));
                if (!$dryRun) {
                    $this->sessionService->remove($code);
                }
                $removed++;
                continue;
            }

            $this->console->line(sprintf('⚠ kept %s (%s)', $code, $decision['reason']));
            $this->console->line(sprintf(
                "  Session orphan: code %s, process PID %d still alive, WA gone. Run 'php scripts/backlog-agent.php stop --code=%s' to terminate cleanly, then re-run prune.",
                $code,
                $this->describeLivePid($session),
                $code,
            ));
            $warnings++;
        }

        $suffix = $dryRun ? ' (dry-run)' : '';
        $this->console->line(sprintf('%d entries removed, %d warnings%s', $removed, $warnings, $suffix));

        return 0;
    }

    /**
     * Decides the action for one session entry.
     *
     * @return array{action: 'remove'|'warn'|'keep', reason: string}
     */
    private function decide(AgentSession $session): array
    {
        if ($session->clientPid === null && $session->tmuxSession === null) {
            return [
                'action' => self::ACTION_REMOVE,
                'reason' => 'launch never finalised — null client_pid + null tmux_session',
            ];
        }

        $alive = $this->sessionDriver->isAlive($session);
        $worktreeMissing = $session->worktree === '' || !is_dir($session->worktree);

        if (!$alive) {
            return [
                'action' => self::ACTION_REMOVE,
                'reason' => $worktreeMissing ? 'process dead, worktree gone' : 'process dead',
            ];
        }

        if ($worktreeMissing) {
            return [
                'action' => self::ACTION_WARN,
                'reason' => 'worktree gone, process still alive',
            ];
        }

        return ['action' => self::ACTION_KEEP, 'reason' => ''];
    }

    /**
     * Returns the most relevant live PID for diagnostic output (client when known, else wrapper).
     */
    private function describeLivePid(AgentSession $session): int
    {
        if ($session->clientPid !== null && $session->clientPid > 0) {
            return $session->clientPid;
        }

        return $session->pid;
    }
}
