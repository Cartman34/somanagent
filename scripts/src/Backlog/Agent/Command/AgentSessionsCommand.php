<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Command;

use Sowapps\SoManAgent\Script\Console;
use Sowapps\SoManAgent\Script\Backlog\Agent\Service\AgentSessionService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Client\AgentClientLauncherRegistry;
use Sowapps\SoManAgent\Script\Backlog\Agent\Enum\AgentClient;
use Sowapps\SoManAgent\Script\Backlog\Agent\Model\SessionInfo;
/**
 * Lists past CLI sessions for the worktree bound to an agent code.
 *
 * Usage:
 *   php scripts/backlog-agent.php agent-history --code=<code>
 */
final class AgentSessionsCommand extends AbstractAgentCommand
{
    private Console $console;
    private AgentSessionService $sessionService;
    private AgentClientLauncherRegistry $registry;

    /**
     * @param Console $console
     * @param AgentSessionService $sessionService
     * @param AgentClientLauncherRegistry $registry
     */
    public function __construct(
        Console $console,
        AgentSessionService $sessionService,
        AgentClientLauncherRegistry $registry,
    ) {
        $this->console = $console;
        $this->sessionService = $sessionService;
        $this->registry = $registry;
    }

    /**
     * {@inheritdoc}
     */
    public function getOptions(): array
    {
        return [
            ['name' => '--code=<code>', 'description' => 'Agent code whose session history to list (required)'],
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

        $session = $this->sessionService->get($code);
        if ($session !== null) {
            $this->sessionService->updateLastSeen($code);
        }

        // Use the worktree stored in sessions.json (reviewer sessions use the developer's WA,
        // not .agent-worktrees/<rXX>).
        $worktree = $session?->worktree;
        if ($worktree === null || !is_dir($worktree)) {
            throw new \RuntimeException(sprintf("Worktree not found for code '%s'.", $code));
        }

        $clientValue = $session?->client->value ?? null;
        if ($clientValue === null) {
            throw new \RuntimeException(sprintf(
                "No active session for code '%s'. Cannot determine which client to query.",
                $code,
            ));
        }

        $client = AgentClient::from($clientValue);
        $launcher = $this->registry->get($client);

        $sessions = $launcher->listSessions($worktree);

        if ($sessions === []) {
            $this->console->line(sprintf('No past sessions found for %s.', $code));

            return 0;
        }

        $this->console->line(sprintf('Past sessions for %s (client: %s):', $code, $clientValue));
        $this->console->line('');

        $headers = ['id', 'started_at', 'last_message_at', 'messages', 'first_prompt'];
        $rows = array_map(fn(SessionInfo $s): array => [
            'id' => $s->id,
            'started_at' => $s->startedAt?->format('Y-m-d H:i') ?? '—',
            'last_message_at' => $s->lastMessageAt?->format('Y-m-d H:i') ?? '—',
            'messages' => (string) ($s->messageCount ?? '—'),
            'first_prompt' => $s->firstPromptExcerpt ?? '—',
        ], $sessions);

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
}
