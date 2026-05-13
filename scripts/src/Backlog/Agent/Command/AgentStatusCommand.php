<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Command;

use SoManAgent\Script\Backlog\Agent\Client\ProcessSignaler;
use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Console;

/**
 * Displays session details for one code, or a summary table for all codes.
 *
 * Usage:
 *   php scripts/backlog-agent.php status [--code=<code>]
 */
final class AgentStatusCommand extends AbstractAgentCommand
{
    private Console $console;
    private string $projectRoot;
    private string $boardPath;
    private AgentSessionService $sessionService;
    private BacklogBoardService $boardService;
    private ProcessSignaler $signaler;

    /**
     * @param Console $console
     * @param string $projectRoot
     * @param string $boardPath
     * @param AgentSessionService $sessionService
     * @param BacklogBoardService $boardService
     * @param ProcessSignaler $signaler
     */
    public function __construct(
        Console $console,
        string $projectRoot,
        string $boardPath,
        AgentSessionService $sessionService,
        BacklogBoardService $boardService,
        ProcessSignaler $signaler,
    ) {
        $this->console = $console;
        $this->projectRoot = $projectRoot;
        $this->boardPath = $boardPath;
        $this->sessionService = $sessionService;
        $this->boardService = $boardService;
        $this->signaler = $signaler;
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'Show session details for one agent or a summary of all sessions';
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions(): array
    {
        return [
            ['name' => '--code=<code>', 'description' => 'Show full details for this agent code'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getUsageExamples(): array
    {
        return [
            'php scripts/backlog-agent.php status',
            'php scripts/backlog-agent.php status --code=d04',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $args, array $options): int
    {
        $codeOption = $this->getSingleOption($options, 'code');

        if ($codeOption !== null) {
            return $this->statusForCode($codeOption);
        }

        return $this->statusAll();
    }

    /**
     * Displays full details for one agent code.
     */
    private function statusForCode(string $code): int
    {
        $session = $this->sessionService->get($code);
        if ($session === null) {
            throw new \RuntimeException(sprintf("No session found for code '%s'.", $code));
        }

        $alive = $session->isAlive($this->signaler);
        $this->sessionService->updateLastSeen($code);
        $relWorktree = str_replace($this->projectRoot . '/', '', $session->worktree);

        if ($session->role->value === 'manager') {
            $current = 'manager ' . $session->worktree;
        } else {
            $current = '—';
            if (is_file($this->boardPath)) {
                try {
                    $board = $this->boardService->loadBoard($this->boardPath);
                    if ($session->role->value === 'reviewer') {
                        $match = $this->boardService->findReviewingEntryByReviewer($board, $code);
                        if ($match !== null) {
                            $entry = $match->getEntry();
                            $feature = $entry->getFeature() ?? '';
                            $task = $entry->getTask() ?? '';
                            $current = '[reviewing] ' . ($task !== '' ? "{$feature}/{$task}" : $feature);
                        }
                    } else {
                        $entries = $this->boardService->findActiveEntriesByAgent($board, $code);
                        if ($entries !== []) {
                            $entry = $entries[0]->getEntry();
                            $feature = $entry->getFeature() ?? '';
                            $task = $entry->getTask() ?? '';
                            $current = $task !== '' ? "{$feature}/{$task}" : $feature;
                        }
                    }
                } catch (\RuntimeException) {
                    // skip
                }
            }
        }

        $this->console->line(sprintf('Code      : %s', $code));
        $this->console->line(sprintf('Role      : %s', $session->role->value));
        $this->console->line(sprintf('Client    : %s', $session->client->value));
        $this->console->line(sprintf('PID       : %d (%s)', $session->pid, $alive ? 'running' : 'dead'));
        $this->console->line(sprintf('Worktree  : %s', $relWorktree));
        $this->console->line(sprintf('Started   : %s', $session->startedAt->format(\DateTimeInterface::ATOM)));
        $this->console->line(sprintf('Last seen : %s', $session->lastSeenAt->format(\DateTimeInterface::ATOM)));
        $this->console->line(sprintf('Session   : %s', $session->sessionId ?? 'null'));
        $this->console->line(sprintf('Current   : %s', $current));

        return 0;
    }

    /**
     * Displays a summary table of all sessions (identical columns to `list`, always shows --all).
     */
    private function statusAll(): int
    {
        $sessions = $this->sessionService->load();

        if ($sessions === []) {
            $this->console->line('No agent sessions.');

            return 0;
        }

        $board = null;
        if (is_file($this->boardPath)) {
            try {
                $board = $this->boardService->loadBoard($this->boardPath);
            } catch (\RuntimeException) {
                // skip board
            }
        }

        $rows = [];
        foreach ($sessions as $code => $session) {
            $alive = $session->isAlive($this->signaler);
            $this->sessionService->updateLastSeen($code);
            $relWorktree = str_replace($this->projectRoot . '/', '', $session->worktree);
            $rows[] = [
                'code' => $code,
                'role' => $session->role->value,
                'client' => $session->client->value,
                'pid' => $session->pid . ' (' . ($alive ? 'running' : 'dead') . ')',
                'worktree' => $relWorktree,
                'started_at' => $session->startedAt->format('Y-m-d H:i'),
                'last_seen_at' => $session->lastSeenAt->format('Y-m-d H:i'),
                'current' => $this->deriveCurrentLabel($session, $board),
            ];
        }

        $headers = ['code', 'role', 'client', 'pid', 'worktree', 'started_at', 'last_seen_at', 'current'];

        /** @var array<string, int> $widths */
        $widths = [];
        foreach ($headers as $h) {
            $widths[$h] = strlen($h);
        }
        foreach ($rows as $row) {
            foreach ($headers as $h) {
                $widths[$h] = max($widths[$h], strlen($row[$h]));
            }
        }

        $line = implode(' | ', array_map(fn(string $h): string => str_pad($h, $widths[$h]), $headers));
        $this->console->line($line);
        $this->console->line(str_repeat('-', strlen($line)));

        foreach ($rows as $row) {
            $this->console->line(implode(' | ', array_map(
                fn(string $h): string => str_pad($row[$h], $widths[$h]),
                $headers,
            )));
        }

        return 0;
    }

    /**
     * Derives the `current` column label: manager CWD, reviewer entry, or developer active entry.
     */
    private function deriveCurrentLabel(AgentSession $session, ?BacklogBoard $board): string
    {
        if ($session->role->value === 'manager') {
            return 'manager ' . $session->worktree;
        }

        if ($board === null) {
            return '—';
        }

        if ($session->role->value === 'reviewer') {
            $match = $this->boardService->findReviewingEntryByReviewer($board, $session->code);
            if ($match !== null) {
                $entry = $match->getEntry();
                $feature = $entry->getFeature() ?? '';
                $task = $entry->getTask() ?? '';

                return '[reviewing] ' . ($task !== '' ? "{$feature}/{$task}" : $feature);
            }

            return '—';
        }

        $entries = $this->boardService->findActiveEntriesByAgent($board, $session->code);

        if ($entries === []) {
            return '—';
        }

        $entry = $entries[0]->getEntry();
        $feature = $entry->getFeature() ?? '';
        $task = $entry->getTask() ?? '';

        return $task !== '' ? "{$feature}/{$task}" : $feature;
    }
}
