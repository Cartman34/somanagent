<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Service;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * GitHub CLI help loader and renderer.
 *
 * Help content lives under scripts/resources/github/commands/ and is loaded lazily.
 * One YAML file per command: pr-create.yaml, pr-merge.yaml, etc.
 */
final class GitHubHelpService
{
    private string $resourceDir;

    /** @var array<string, array<string, mixed>> */
    private array $commandHelp = [];

    public function __construct(?string $resourceDir = null)
    {
        $this->resourceDir = $resourceDir ?? (dirname(__DIR__, 2) . '/resources/github/commands');
    }

    /**
     * Render the help output for a single command.
     *
     * Appends execution-mode options (dry-run, verbose, no-verbose) after
     * command-specific options so they always appear last.
     *
     * @param array<array{name: string, description: string}> $execOpts
     * @throws \RuntimeException When the command YAML file does not exist
     */
    public function renderCommandHelp(string $command, array $execOpts): string
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
        $allOptions = array_merge($options, $execOpts);
        if ($allOptions !== []) {
            $lines[] = '';
            $lines[] = 'Options:';
            foreach ($allOptions as $option) {
                $lines[] = "  {$option['name']}";
                $lines[] = "    {$option['description']}";
            }
        }

        $examples = $help['examples'] ?? [];
        if (!is_array($examples) || $examples === []) {
            throw new \RuntimeException(sprintf(
                'Invalid github help file for `%s`: `examples` must be a non-empty list.',
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
                    'Invalid github help file for `%s`: `notes` must be a list when present.',
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
     * @throws \RuntimeException When the command YAML file does not exist or is invalid
     */
    private function loadCommandHelp(string $command): array
    {
        if (isset($this->commandHelp[$command])) {
            return $this->commandHelp[$command];
        }

        $path = $this->resourceDir . '/' . $command . '.yaml';
        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf(
                'Unknown command: %s. Run `php scripts/github.php --help` for the available commands.',
                $command,
            ));
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new \RuntimeException(sprintf(
                'Invalid github help file `%s`: %s',
                $path,
                $exception->getMessage(),
            ), previous: $exception);
        }

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf(
                'Invalid github help file `%s`: expected a YAML mapping at the root.',
                $path,
            ));
        }

        if (!isset($data['description'])) {
            throw new \RuntimeException(sprintf(
                'Invalid github help file for `%s`: `description` is required.',
                $command,
            ));
        }

        $this->commandHelp[$command] = $data;

        return $this->commandHelp[$command];
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
                'name'        => (string) $item['name'],
                'description' => (string) $item['description'],
            ];
        }

        return $normalized;
    }
}
