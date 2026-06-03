<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace Sowapps\SoManAgent\Script\Service;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Stateless help service for YAML-based CLI scripts — provides typed RunnerHelp and CommandHelp objects.
 *
 * Resource layout, computed from runner and command names:
 * - {resourcesBasePath}/{runnerName}/help.yaml            runner-level help
 * - {resourcesBasePath}/{runnerName}/commands/{cmd}.yaml  per-command help
 *
 * Expected runner help YAML keys: description (required), options, examples.
 * Expected per-command YAML keys: description (required), arguments, options, examples, notes.
 */
final class CommandHelpService
{
    /**
     * @param string $resourcesBasePath Absolute path to the directory containing per-runner help YAML subdirectories
     */
    public function __construct(private string $resourcesBasePath)
    {
    }

    /**
     * Load and return the runner-level help DTO.
     *
     * @throws \RuntimeException When the runner is unknown or its help YAML is invalid
     */
    public function getRunnerHelp(string $runnerName): RunnerHelp
    {
        $this->assertRunnerExists($runnerName);

        $runnerDir = $this->runnerDir($runnerName);
        $path = $runnerDir . '/help.yaml';

        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf('Missing runner help file: %s', $path));
        }

        $raw = $this->parseYamlFile($path, sprintf('runner help file for `%s`', $runnerName));

        if (!isset($raw['description'])) {
            throw new \RuntimeException(sprintf(
                'Invalid runner help file for `%s`: `description` is required.',
                $runnerName,
            ));
        }

        $examples = $raw['examples'] ?? [];
        if (!is_array($examples)) {
            throw new \RuntimeException(sprintf(
                'Invalid runner help file for `%s`: `examples` must be a list.',
                $runnerName,
            ));
        }

        return new RunnerHelp(
            description:  (string) $raw['description'],
            options:      $this->normalizeToParams($raw['options'] ?? [], "runner options for {$runnerName}"),
            examples:     array_values(array_map(static fn(mixed $v): string => (string) $v, $examples)),
            commandNames: $this->listCommandNames($runnerDir . '/commands'),
        );
    }

    /**
     * Load and return the typed help DTO for a command.
     *
     * @throws \RuntimeException When the runner is unknown, the command YAML file does not exist, or is invalid
     */
    public function getCommandHelp(string $runnerName, string $commandName): CommandHelp
    {
        $this->assertRunnerExists($runnerName);

        $path = $this->runnerDir($runnerName) . '/commands/' . $commandName . '.yaml';

        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf(
                'Unknown command: %s. Run with --help for the list of available commands.',
                $commandName,
            ));
        }

        $raw = $this->parseYamlFile($path, sprintf('help file for `%s`', $commandName));

        if (!isset($raw['description'])) {
            throw new \RuntimeException(sprintf(
                'Invalid help file for `%s`: `description` is required.',
                $commandName,
            ));
        }

        $examples = $raw['examples'] ?? [];
        if (!is_array($examples)) {
            throw new \RuntimeException(sprintf(
                'Invalid help file for `%s`: `examples` must be a list.',
                $commandName,
            ));
        }

        $notes = $raw['notes'] ?? [];
        if (!is_array($notes)) {
            throw new \RuntimeException(sprintf(
                'Invalid help file for `%s`: `notes` must be a list when present.',
                $commandName,
            ));
        }

        return new CommandHelp(
            name:        $commandName,
            description: (string) $raw['description'],
            arguments:   $this->normalizeToParams($raw['arguments'] ?? [], "arguments for {$commandName}"),
            options:     $this->normalizeToParams($raw['options'] ?? [], "options for {$commandName}"),
            examples:    array_values(array_map(static fn(mixed $v): string => (string) $v, $examples)),
            notes:       array_values(array_map(static fn(mixed $v): string => (string) $v, $notes)),
        );
    }

    /**
     * Render the full help output for one command.
     *
     * @param list<CommandParamHelp> $execOpts Execution-mode options appended after command options
     * @throws \RuntimeException When the command YAML file does not exist or is invalid
     */
    public function renderCommandHelp(string $runnerName, string $commandName, array $execOpts = []): string
    {
        $help = $this->getCommandHelp($runnerName, $commandName);
        $lines = [$help->name, $help->description];

        if ($help->arguments !== []) {
            $lines[] = '';
            $lines[] = 'Arguments:';
            foreach ($help->arguments as $argument) {
                $lines[] = "  {$argument->name}";
                $lines[] = "    {$argument->description}";
            }
        }

        $allOptions = array_merge($help->options, $execOpts);
        if ($allOptions !== []) {
            $lines[] = '';
            $lines[] = 'Options:';
            foreach ($allOptions as $option) {
                $lines[] = "  {$option->name}";
                $lines[] = "    {$option->description}";
            }
        }

        if ($help->examples === []) {
            throw new \RuntimeException(sprintf(
                'Invalid help file for `%s`: `examples` must be a non-empty list.',
                $commandName,
            ));
        }

        $lines[] = '';
        $lines[] = 'Examples:';
        foreach ($help->examples as $example) {
            $lines[] = '  ' . $example;
        }

        if ($help->notes !== []) {
            $lines[] = '';
            $lines[] = 'Notes:';
            foreach ($help->notes as $note) {
                $lines[] = '  - ' . $note;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function runnerDir(string $runnerName): string
    {
        return $this->resourcesBasePath . '/' . $runnerName;
    }

    /**
     * @throws \RuntimeException When the runner directory does not exist
     */
    private function assertRunnerExists(string $runnerName): void
    {
        if (!is_dir($this->runnerDir($runnerName))) {
            throw new \RuntimeException(sprintf(
                'Unknown runner `%s`: missing help directory %s',
                $runnerName,
                $this->runnerDir($runnerName),
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function listCommandNames(string $commandsDir): array
    {
        if (!is_dir($commandsDir)) {
            return [];
        }

        $paths = glob($commandsDir . '/*.yaml');
        if ($paths === false) {
            throw new \RuntimeException('Unable to list command help files.');
        }

        sort($paths);

        return array_map(
            static fn(string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $paths,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseYamlFile(string $path, string $label): array
    {
        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new \RuntimeException(sprintf(
                'Invalid %s `%s`: %s',
                $label,
                $path,
                $exception->getMessage(),
            ), previous: $exception);
        }

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf(
                'Invalid %s `%s`: expected a YAML mapping at the root.',
                $label,
                $path,
            ));
        }

        return $data;
    }

    /**
     * Normalize a raw YAML list into typed CommandParamHelp objects.
     *
     * @param  mixed                  $items
     * @return list<CommandParamHelp>
     */
    private function normalizeToParams(mixed $items, string $label): array
    {
        if ($items === [] || $items === null) {
            return [];
        }
        if (!is_array($items)) {
            throw new \RuntimeException(sprintf('Invalid %s: expected a list.', $label));
        }

        $params = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['name']) || !isset($item['description'])) {
                throw new \RuntimeException(sprintf(
                    'Invalid %s: each entry must define `name` and `description`.',
                    $label,
                ));
            }

            $params[] = new CommandParamHelp((string) $item['name'], (string) $item['description']);
        }

        return $params;
    }
}
