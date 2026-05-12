<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog\Service;

use RuntimeException;
use SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use SoManAgent\Script\Service\CommandHelpService;
use SoManAgent\Script\Service\CommandParamHelp;

/**
 * Strict CLI option validator for scripts/backlog.php.
 *
 * Combines the global option allowlist from {@see BacklogCliOption::globalOptionNames()}
 * with the per-command options declared in `scripts/resources/backlog/commands/*.yaml` and
 * rejects any option not present in either set.
 */
final class BacklogCliOptionValidator
{
    private const RUNNER_NAME = 'backlog';

    /**
     * @param CommandHelpService $helpService Loader for the per-command YAML help files
     */
    public function __construct(private CommandHelpService $helpService)
    {
    }

    /**
     * Asserts that every option key is either a global option or declared by the command YAML.
     *
     * Both the `--option=value` and `--option value` forms produce the same option key in the
     * parsed map, so this check covers both shapes.
     *
     * @param string $command The dispatched backlog command name
     * @param array<string, bool|string|array<bool|string>> $options Parsed option map from the CLI
     * @throws RuntimeException When at least one option key is not accepted for the command
     */
    public function assertCommandOptionsAccepted(string $command, array $options): void
    {
        $unknown = $this->extractUnknown($options, $this->collectAllowedOptions($command));
        if ($unknown === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Unknown option(s) for command `%s`: %s',
            $command,
            $this->formatUnknown($unknown),
        ));
    }

    /**
     * Asserts that every option key belongs to the global allowlist.
     *
     * Used for the no-command path where per-command options are not in scope.
     *
     * @param array<string, bool|string|array<bool|string>> $options Parsed option map from the CLI
     * @throws RuntimeException When at least one option key is not a global option
     */
    public function assertGlobalOptionsAccepted(array $options): void
    {
        $unknown = $this->extractUnknown($options, BacklogCliOption::globalOptionNames());
        if ($unknown === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Unknown global option(s): %s',
            $this->formatUnknown($unknown),
        ));
    }

    /**
     * @return list<string>
     */
    private function collectAllowedOptions(string $command): array
    {
        $help = $this->helpService->getCommandHelp(self::RUNNER_NAME, $command);
        $perCommand = array_map(
            static fn(CommandParamHelp $option): string => ltrim($option->name, '-'),
            $help->options,
        );

        return array_values(array_unique(array_merge(BacklogCliOption::globalOptionNames(), $perCommand)));
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

    /**
     * @param list<string> $unknown
     */
    private function formatUnknown(array $unknown): string
    {
        return implode(', ', array_map(static fn(string $name): string => '--' . $name, $unknown));
    }
}
