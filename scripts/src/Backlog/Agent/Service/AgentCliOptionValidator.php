<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Backlog\Agent\Service;

use RuntimeException;

/**
 * Strict CLI option validator for scripts/backlog-agent.php.
 *
 * Combines the runner-level allowlist (`--help`, `--force-current-worktree`)
 * with the per-command options declared by each subcommand's `getOptions()`
 * and rejects any option not present in either set. Both the `--option=value`
 * and `--option value` forms are validated because they produce the same key
 * in the parsed option map.
 */
final class AgentCliOptionValidator
{
    /**
     * Runner-level options always accepted regardless of the dispatched subcommand.
     *
     * Keep this set in sync with the runner: `--help` triggers the per-command help
     * and `--force-current-worktree` is the internal proxy bypass flag handled by
     * `WorktreeScriptProxy`.
     */
    private const RUNNER_OPTIONS = ['help', 'force-current-worktree'];

    /**
     * Asserts that every option key is either a runner-level option or declared by the command.
     *
     * @param string $command The dispatched subcommand name
     * @param array<array{name: string, description: string}> $declaredOptions Options returned by the subcommand's getOptions()
     * @param array<string, bool|string|array<bool|string>> $parsedOptions Parsed option map from the CLI
     * @throws RuntimeException When at least one option key is not accepted for the command
     */
    public function assertCommandOptionsAccepted(string $command, array $declaredOptions, array $parsedOptions): void
    {
        $allowed = $this->collectAllowed($declaredOptions);
        $unknown = $this->extractUnknown($parsedOptions, $allowed);
        if ($unknown === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Unknown option(s) for command `%s`: %s',
            $command,
            implode(', ', array_map(static fn(string $name): string => '--' . $name, $unknown)),
        ));
    }

    /**
     * Asserts that every option key belongs to the runner-level allowlist.
     *
     * Used for the help-only paths (no command, or `help [<command>]`) where
     * per-command options are not in scope.
     *
     * @param array<string, bool|string|array<bool|string>> $parsedOptions Parsed option map from the CLI
     * @throws RuntimeException When at least one option key is not a runner-level option
     */
    public function assertGlobalOptionsAccepted(array $parsedOptions): void
    {
        $unknown = $this->extractUnknown($parsedOptions, self::RUNNER_OPTIONS);
        if ($unknown === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Unknown global option(s): %s',
            implode(', ', array_map(static fn(string $name): string => '--' . $name, $unknown)),
        ));
    }

    /**
     * Extracts the canonical option keys from a command's declared option list.
     *
     * Accepts shapes like `--code=<code>`, `--developer=<dXX>`, `--reset`. The
     * leading dashes and any value placeholder after `=` are stripped to keep
     * only the option key.
     *
     * @param array<array{name: string, description: string}> $declaredOptions
     * @return list<string>
     */
    private function collectAllowed(array $declaredOptions): array
    {
        $names = [];
        foreach ($declaredOptions as $option) {
            $name = ltrim($option['name'], '-');
            $name = explode('=', $name, 2)[0];
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique(array_merge(self::RUNNER_OPTIONS, $names)));
    }

    /**
     * @param array<string, bool|string|array<bool|string>> $options
     * @param list<string> $allowed
     * @return list<string>
     */
    private function extractUnknown(array $options, array $allowed): array
    {
        $unknown = array_keys(array_diff_key($options, array_flip($allowed)));
        sort($unknown);

        return $unknown;
    }
}
