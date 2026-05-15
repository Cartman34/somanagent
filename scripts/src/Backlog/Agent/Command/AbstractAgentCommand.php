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
     * @param array<string, string|bool|array<bool|string>> $options Parsed options
     * @return int Exit code
     */
    abstract public function handle(array $args, array $options): int;

    /**
     * Returns the value of a single-value option, or null when absent or used as a bare flag.
     *
     * Throws when the option was repeated on the CLI (array value). Mirrors the behavior of
     * AbstractScriptRunner::getSingleOption() so commands can read scalar options safely after
     * parseArgs widened the option map type.
     *
     * @param array<string, string|bool|array<bool|string>> $options
     */
    protected function getSingleOption(array $options, string $name): ?string
    {
        $val = $options[$name] ?? null;
        if ($val === null || $val === true) {
            return null;
        }
        if (is_array($val)) {
            throw new \RuntimeException(sprintf('Option --%s cannot be repeated.', $name));
        }

        return (string) $val;
    }
}
