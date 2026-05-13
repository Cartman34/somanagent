<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Command;

use SoManAgent\Script\Backlog\Agent\Model\AgentSession;
use SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use SoManAgent\Script\Backlog\Model\BacklogBoard;
use SoManAgent\Script\Backlog\Service\BacklogBoardService;
use SoManAgent\Script\Console;

/**
 * Lists active and/or stale agent sessions.
 *
 * Usage:
 *   php scripts/backlog-agent.php list [--running] [--all]
 */
final class AgentListCommand extends AbstractAgentCommand
{
    private Console $console;
    private string $projectRoot;
    private string $boardPath;
    private AgentSessionService $sessionService;
    private BacklogBoardService $boardService;

    /**
     * @param Console $console
     * @param string $projectRoot
     * @param string $boardPath
     * @param AgentSessionService $sessionService
     * @param BacklogBoardService $boardService
     */
    public function __construct(
        Console $console,
        string $projectRoot,
        string $boardPath,
        AgentSessionService $sessionService,
        BacklogBoardService $boardService,
    ) {
        $this->console = $console;
        $this->projectRoot = $projectRoot;
        $this->boardPath = $boardPath;
        $this->sessionService = $sessionService;
        $this->boardService = $boardService;
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return 'List active agent sessions';
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions(): array
    {
        return [
            ['name' => '--running', 'description' => 'Show only sessions with a live PID'],
            ['name' => '--all', 'description' => 'Include stale entries (PID dead)'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getUsageExamples(): array
    {
        return [
            'php scripts/backlog-agent.php list',
            'php scripts/backlog-agent.php list --running',
            'php scripts/backlog-agent.php list --all',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $args, array $options): int
    {
        $filterRunning = isset($options['running']);
        $showAll = isset($options['all']);

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
            $alive = $session->isAlive();
            $this->sessionService->updateLastSeen($code);

            if ($filterRunning && !$alive) {
                continue;
            }
            if (!$showAll && !$alive) {
                continue;
            }

            $current = $this->deriveCurrentLabel($session, $board);
            $relWorktree = str_replace($this->projectRoot . '/', '', $session->worktree);

            $rows[] = [
                'code' => $code,
                'role' => $session->role->value,
                'client' => $session->client->value,
                'pid' => $session->pid . ' (' . ($alive ? 'running' : 'dead') . ')',
                'worktree' => $relWorktree,
                'started_at' => $session->startedAt->format('Y-m-d H:i'),
                'last_seen_at' => $session->lastSeenAt->format('Y-m-d H:i'),
                'current' => $current,
            ];
        }

        if ($rows === []) {
            $this->console->line('No matching sessions.');

            return 0;
        }

        $headers = ['code', 'role', 'client', 'pid', 'worktree', 'started_at', 'last_seen_at', 'current'];
        $this->printTable($headers, $rows);

        return 0;
    }

    /**
     * Prints a simple ASCII table.
     *
     * @param list<string> $headers
     * @param list<array<string, string>> $rows
     */
    private function printTable(array $headers, array $rows): void
    {
        /** @var array<string, int> $widths */
        $widths = [];
        foreach ($headers as $h) {
            $widths[$h] = strlen($h);
        }
        foreach ($rows as $row) {
            foreach ($headers as $h) {
                $widths[$h] = max($widths[$h], strlen($row[$h] ?? ''));
            }
        }

        $line = implode(' | ', array_map(fn(string $h): string => str_pad($h, $widths[$h]), $headers));
        $this->console->line($line);
        $this->console->line(str_repeat('-', strlen($line)));

        foreach ($rows as $row) {
            $this->console->line(implode(' | ', array_map(
                fn(string $h): string => str_pad($row[$h] ?? '', $widths[$h]),
                $headers,
            )));
        }
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

        $code = $session->code;

        if ($session->role->value === 'reviewer') {
            $match = $this->boardService->findReviewingEntryByReviewer($board, $code);
            if ($match !== null) {
                $entry = $match->getEntry();
                $feature = $entry->getFeature() ?? '';
                $task = $entry->getTask() ?? '';

                return '[reviewing] ' . ($task !== '' ? "{$feature}/{$task}" : $feature);
            }

            return '—';
        }

        $entries = $this->boardService->findActiveEntriesByAgent($board, $code);

        if ($entries === []) {
            return '—';
        }

        $entry = $entries[0]->getEntry();
        $feature = $entry->getFeature() ?? '';
        $task = $entry->getTask() ?? '';

        return $task !== '' ? "{$feature}/{$task}" : $feature;
    }
}
