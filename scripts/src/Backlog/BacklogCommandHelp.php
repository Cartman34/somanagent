<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Backlog;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Backlog CLI help loader and renderer.
 *
 * Help content lives under scripts/resources/backlog/ and is loaded lazily:
 * - help.yaml for global help
 * - commands/<command>.yaml for one specific command
 */
final class BacklogCommandHelp
{
    private string $resourceDir;

    /** @var array<string, mixed>|null */
    private ?array $globalHelp = null;

    /** @var array<string, array<string, mixed>> */
    private array $commandHelp = [];

    public function __construct(?string $resourceDir = null)
    {
        $this->resourceDir = $resourceDir ?? dirname(__DIR__, 2) . '/resources/backlog';
    }

    /**
     * @return array<array{name: string, description: string}>
     */
    public function getCommands(): array
    {
        $commandsDir = $this->resourceDir . '/commands';
        $paths = glob($commandsDir . '/*.yaml');
        if ($paths === false) {
            throw new \RuntimeException('Unable to list backlog help command files.');
        }

        sort($paths);

        $commands = [];
        foreach ($paths as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $data = $this->loadCommandHelp($name);
            $commands[] = [
                'name' => $name,
                'description' => (string) ($data['description'] ?? $name),
            ];
        }

        return $commands;
    }

    /**
     * @param array<array{name: string, description: string}> $executionModeOptions
     * @return array<array{name: string, description: string}>
     */
    public function getOptions(array $executionModeOptions): array
    {
        $globalHelp = $this->loadGlobalHelp();
        $options = $this->normalizeNameDescriptionList($globalHelp['options'] ?? [], 'global options');

        return array_merge($options, $executionModeOptions);
    }

    /**
     * @return array<string>
     */
    public function getUsageExamples(): array
    {
        $globalHelp = $this->loadGlobalHelp();
        $examples = $globalHelp['examples'] ?? [];
        if (!is_array($examples)) {
            throw new \RuntimeException('Invalid backlog help file: `examples` must be a list.');
        }

        return array_map(static fn(mixed $value): string => (string) $value, $examples);
    }

    public function renderCommandHelp(string $command): string
    {
        $help = $this->loadCommandHelp($command);
        $lines = [$command, (string) ($help['description'] ?? $command)];

        $arguments = $this->normalizeNameDescriptionList($help['arguments'] ?? [], sprintf('arguments for %s', $command));
        if ($arguments !== []) {
            $lines[] = '';
            $lines[] = 'Arguments:';
            foreach ($arguments as $argument) {
                $lines[] = "  {$argument['name']}";
                $lines[] = "    {$argument['description']}";
            }
        }

        $options = $this->normalizeNameDescriptionList($help['options'] ?? [], sprintf('options for %s', $command));
        if ($options !== []) {
            $lines[] = '';
            $lines[] = 'Options:';
            foreach ($options as $option) {
                $lines[] = "  {$option['name']}";
                $lines[] = "    {$option['description']}";
            }
        }

        $examples = $help['examples'] ?? [];
        if (!is_array($examples) || $examples === []) {
            throw new \RuntimeException(sprintf(
                'Invalid backlog help file for `%s`: `examples` must be a non-empty list.',
                $command,
            ));
        }

        $lines[] = '';
        $lines[] = 'Examples:';
        foreach ($examples as $example) {
            $lines[] = '  ' . (string) $example;
        }

        $notes = $help['notes'] ?? [];
        if ($notes !== []) {
            if (!is_array($notes)) {
                throw new \RuntimeException(sprintf(
                    'Invalid backlog help file for `%s`: `notes` must be a list when present.',
                    $command,
                ));
            }
            $lines[] = '';
            $lines[] = 'Notes:';
            foreach ($notes as $note) {
                $lines[] = '  - ' . (string) $note;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function loadGlobalHelp(): array
    {
        if ($this->globalHelp !== null) {
            return $this->globalHelp;
        }

        $path = $this->resourceDir . '/help.yaml';
        $data = $this->parseYamlFile($path, 'backlog global help');
        if (!isset($data['description'])) {
            throw new \RuntimeException('Invalid backlog help file: `description` is required.');
        }

        $this->globalHelp = $data;

        return $this->globalHelp;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCommandHelp(string $command): array
    {
        if (isset($this->commandHelp[$command])) {
            return $this->commandHelp[$command];
        }

        $path = $this->resourceDir . '/commands/' . $command . '.yaml';
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf(
                'Unknown backlog command: %s. Run `php scripts/backlog.php help` for the available commands.',
                $command,
            ));
        }

        $data = $this->parseYamlFile($path, sprintf('backlog help for `%s`', $command));
        if (!isset($data['description'])) {
            throw new \RuntimeException(sprintf(
                'Invalid backlog help file for `%s`: `description` is required.',
                $command,
            ));
        }

        $this->commandHelp[$command] = $data;

        return $this->commandHelp[$command];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseYamlFile(string $path, string $label): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf(
                'Missing %s file: %s',
                $label,
                $path,
            ));
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new \RuntimeException(sprintf(
                'Invalid %s file `%s`: %s',
                $label,
                $path,
                $exception->getMessage(),
            ), previous: $exception);
        }

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf(
                'Invalid %s file `%s`: expected a YAML mapping at the root.',
                $label,
                $path,
            ));
        }

        return $data;
    }

    /**
     * @param mixed $items
     * @return array<array{name: string, description: string}>
     */
    private function normalizeNameDescriptionList(mixed $items, string $label): array
    {
        if ($items === [] || $items === null) {
            return [];
        }
        if (!is_array($items)) {
            throw new \RuntimeException(sprintf('Invalid %s: expected a list.', $label));
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['name']) || !isset($item['description'])) {
                throw new \RuntimeException(sprintf(
                    'Invalid %s: each entry must define `name` and `description`.',
                    $label,
                ));
            }

            $normalized[] = [
                'name' => (string) $item['name'],
                'description' => (string) $item['description'],
            ];
        }

        return $normalized;
    }
}
