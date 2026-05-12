<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Agent\Command;

/**
 * Base class for all backlog-agent subcommands.
 *
 * Each subcommand exposes metadata methods consumed by BacklogAgentRunner
 * for help rendering, and a handle() method for execution.
 */
abstract class AbstractAgentCommand
{
    /**
     * One-line description shown in the global command list.
     */
    abstract public function getDescription(): string;

    /**
     * Positional arguments declarations for help display.
     *
     * @return array<array{name: string, description: string}>
     */
    public function getArguments(): array
    {
        return [];
    }

    /**
     * Option declarations for help display.
     *
     * @return array<array{name: string, description: string}>
     */
    public function getOptions(): array
    {
        return [];
    }

    /**
     * Usage examples shown under the help body.
     *
     * @return array<string>
     */
    public function getUsageExamples(): array
    {
        return [];
    }

    /**
     * Executes the subcommand.
     *
     * @param list<string> $args Positional arguments (script name and subcommand already removed)
     * @param array<string, string|bool> $options Parsed options
     * @return int Exit code
     */
    abstract public function handle(array $args, array $options): int;
}
