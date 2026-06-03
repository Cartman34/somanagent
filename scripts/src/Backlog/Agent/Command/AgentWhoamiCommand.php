<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Command;

use Sowapps\SoManAgent\Script\Console;
use Sowapps\SoManAgent\Script\Backlog\Service\BacklogBoardService;
use Sowapps\SoManAgent\Script\Backlog\Agent\Command\AbstractAgentCommand;
/**
 * Displays context information for the current agent session.
 *
 * Intended to be run from inside a WA. Reads SOMANAGER_AGENT / ROLE / CLIENT
 * env vars injected at session start.
 *
 * Usage:
 *   php scripts/backlog-agent.php whoami
 */
final class AgentWhoamiCommand extends AbstractAgentCommand
{
    private Console $console;
    private string $boardPath;
    private BacklogBoardService $boardService;

    /**
     * @param Console $console
     * @param string $boardPath
     * @param BacklogBoardService $boardService
     */
    public function __construct(
        Console $console,
        string $boardPath,
        BacklogBoardService $boardService,
    ) {
        $this->console = $console;
        $this->boardPath = $boardPath;
        $this->boardService = $boardService;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $args, array $options): int
    {
        $code = (string) (getenv('SOMANAGER_AGENT') ?: '');
        $role = (string) (getenv('SOMANAGER_ROLE') ?: '');
        $client = (string) (getenv('SOMANAGER_CLIENT') ?: '');

        if ($code === '' || $role === '' || $client === '') {
            throw new \RuntimeException('This command must be run from a session started by backlog-agent.php.');
        }

        $waPath = (string) getcwd();
        $contextFile = $waPath . '/local/agent-context.md';
        $current = 'no active task';

        if (is_file($this->boardPath)) {
            try {
                $board = $this->boardService->loadBoard($this->boardPath);

                if ($role === 'reviewer') {
                    $match = $this->boardService->findReviewingEntryByReviewer($board, $code);
                    if ($match !== null) {
                        $entry = $match->getEntry();
                        $feature = $entry->getFeature() ?? '';
                        $task = $entry->getTask() ?? '';
                        $devCode = $entry->getDeveloper() ?? '';
                        $ref = $task !== '' ? "{$feature}/{$task}" : $feature;
                        $current = "[reviewing] {$ref} (developer: {$devCode})";
                    } else {
                        $current = 'no review assigned';
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

        $this->console->line(sprintf('Code          : %s', $code));
        $this->console->line(sprintf('Role          : %s', $role));
        $this->console->line(sprintf('Client        : %s', $client));
        $this->console->line(sprintf('WA path       : %s', $waPath));
        $this->console->line(sprintf('Active task   : %s', $current));
        $this->console->line(sprintf('Context file  : %s', $contextFile));

        return 0;
    }
}
