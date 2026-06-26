<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Runner;

use Sowapps\SoManAgent\Script\Client\Agent\AbstractAgentAuthManager;
use Sowapps\SoManAgent\Script\Client\Agent\AgentAuthStatus;
use Sowapps\Toolkit\Runner\AbstractScriptRunner;

/**
 * Template for the agent auth runners (Claude / Codex / OpenCode).
 *
 * Owns the shared dispatch (status / sync / login), the interactive overwrite confirmation, and the
 * rendering of the structured {@see AgentAuthStatus} the manager collects. Each concrete runner
 * supplies its name/description/commands/options/examples and its injected manager.
 */
abstract class AbstractAgentAuthRunner extends AbstractScriptRunner
{
    /**
     * Returns the agent display label used in confirmation and progress messages (e.g. "Claude").
     */
    abstract protected function getAgentLabel(): string;

    /**
     * Returns the manager that performs the auth operations for this agent.
     */
    abstract protected function getManager(): AbstractAgentAuthManager;

    protected function getCommands(): array
    {
        return [
            ['name' => 'status', 'description' => 'Show current auth status'],
            ['name' => 'sync', 'description' => 'Sync auth from WSL to Docker'],
            ['name' => 'login', 'description' => 'Login and sync'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--force', 'description' => 'Force overwrite existing auth (sync) or re-authenticate (login)'],
        ];
    }

    /**
     * Dispatches the requested auth action to the manager, rendering progress and results.
     *
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        [$positional, $options] = $this->parseArgs($args);

        $command = $positional[0] ?? 'status';
        $force = isset($options['force']);

        try {
            match ($command) {
                'status' => $this->renderStatus($this->getManager()->collectStatus()),
                'sync' => $this->runSync($force),
                'login' => $this->runLogin(),
                default => throw new \RuntimeException(sprintf('Unknown command "%s". Use status, sync, or login.', $command)),
            };
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        }

        return 0;
    }

    /**
     * Confirms the overwrite (unless forced), then runs the sync sequence with progress messages.
     */
    protected function runSync(bool $force): void
    {
        if (!$force) {
            $this->confirmOverwrite(sprintf(
                'This will overwrite the Docker %s auth copy from your WSL auth state.',
                $this->getAgentLabel(),
            ));
        }

        $this->console->step(sprintf('Syncing WSL %s auth to Docker shared directory', $this->getAgentLabel()));
        $this->getManager()->sync();
        $this->console->ok(sprintf('Docker %s auth copy updated from WSL.', $this->getAgentLabel()));
    }

    /**
     * Runs the login-and-sync sequence with progress messages.
     */
    protected function runLogin(): void
    {
        $this->console->step(sprintf('Running %s login in WSL', $this->getAgentLabel()));
        $this->getManager()->loginAndSync();
        $this->console->ok(sprintf('Docker %s auth copy updated from WSL.', $this->getAgentLabel()));
    }

    /**
     * Asks for an explicit confirmation before overwriting the Docker-side auth copy.
     */
    protected function confirmOverwrite(string $message): void
    {
        $this->console->warn($message);
        $this->console->warn('Type "yes" to continue:');

        $confirmation = trim((string) fgets(STDIN));
        if ($confirmation !== 'yes') {
            throw new \RuntimeException('Aborted.');
        }
    }

    /**
     * Renders the structured auth status snapshot to the terminal.
     */
    protected function renderStatus(AgentAuthStatus $status): void
    {
        foreach ($status->getPathGroups() as $group) {
            $this->console->step($group['label']);
            foreach ($group['paths'] as $pathState) {
                $this->console->info($this->formatPathState($pathState));
            }
        }

        foreach ($status->getComparisons() as $comparison) {
            $this->console->info(sprintf(
                '%s %s with WSL.',
                $comparison['label'],
                $comparison['inSync'] ? 'is in sync' : 'differs',
            ));
        }

        foreach ($status->getNotes() as $note) {
            $this->console->warn($note);
        }

        foreach ($status->getCommandOutputs() as $commandOutput) {
            $this->console->step($commandOutput['label']);
            $this->renderCommandOutput($commandOutput['exitCode'], $commandOutput['output']);
        }
    }

    /**
     * Formats a structured path state into the legacy human-readable line.
     *
     * @param array{path: string, type: string, size: int, mtime: int} $pathState
     */
    protected function formatPathState(array $pathState): string
    {
        return match ($pathState['type']) {
            AgentAuthStatus::TYPE_DIR => sprintf(
                '%s [dir, mtime=%s]',
                $pathState['path'],
                date(\DateTimeInterface::ATOM, $pathState['mtime'] ?: time()),
            ),
            AgentAuthStatus::TYPE_FILE => sprintf(
                '%s [file, %d bytes, mtime=%s]',
                $pathState['path'],
                $pathState['size'],
                date(\DateTimeInterface::ATOM, $pathState['mtime'] ?: time()),
            ),
            default => sprintf('%s [missing]', $pathState['path']),
        };
    }

    /**
     * Prints a captured probe output followed by its success/failure line.
     */
    protected function renderCommandOutput(int $exitCode, string $output): void
    {
        foreach (explode("\n", rtrim($output, "\n")) as $line) {
            if ($line !== '') {
                $this->console->line($line);
            }
        }

        if ($exitCode === 0) {
            $this->console->ok('Command completed successfully.');

            return;
        }

        $this->console->warn(sprintf('Command exited with status %d.', $exitCode));
    }
}
