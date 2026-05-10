<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Backlog\BacklogCommandFactory;
use SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Service\BacklogCliOptionValidator;
use SoManAgent\Script\Service\CommandHelpService;

/**
 * Local backlog workflow orchestrator.
 */
final class BacklogRunner extends AbstractScriptRunner
{
    public const NAME = 'backlog';

    private const DEFAULT_BOARD_PATH = 'local/backlog-board.md';
    private const DEFAULT_REVIEW_FILE_PATH = 'local/backlog-review.md';
    private const DEFAULT_WORKTREES_DIR = '.agent-worktrees';
    private const LEGACY_WORKTREES_DIR = '.worktrees';

    private const INITIAL_BOARD_CONTENT = "# Backlog board\n\n## To do\n\n## In progress\n\n## Suggestions\n";
    private const INITIAL_REVIEW_CONTENT = "# Backlog review\n\n## Usage rules\n\n## Current review\n\nNo review in progress.\n";

    private ?string $boardPath = null;
    private ?string $reviewFilePath = null;
    private ?string $worktreesRoot = null;

    private ?BacklogCommandFactory $commandFactory = null;
    private ?BacklogCliOptionValidator $optionValidator = null;

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function printHelp(): void
    {
        $this->printYamlHelp();
    }

    /**
     * Executes one backlog workflow command.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        [$parsedArgs, $options] = $this->parseArgs(array_values($args));
        $command = array_shift($parsedArgs) ?? '';
        $commandArgs = $parsedArgs;
        $this->configureExecutionModes($options);
        $this->configureTestFileOverrides($options);

        try {
            if ($command === '' || $command === BacklogCommandName::HELP->value) {
                $this->optionValidator()->assertGlobalOptionsAccepted($options);
            } else {
                $this->optionValidator()->assertCommandOptionsAccepted($command, $options);
            }
        } catch (\Exception $e) {
            $this->console->fail($e->getMessage());
        }

        if ($command === '') {
            $this->printHelp();

            return 0;
        }

        if ($command === BacklogCommandName::HELP->value) {
            $targetCommand = $commandArgs[0] ?? '';
            if ($targetCommand === '') {
                $this->printHelp();

                return 0;
            }

            $this->printCommandHelp($targetCommand);

            return 0;
        }

        if (isset($options[BacklogCliOption::HELP->value])) {
            $this->printCommandHelp($command);

            return 0;
        }

        try {
            $this->initializeLocalFiles();

            return $this->handleCommand($command, $commandArgs, $options);
        } catch (\Exception $e) {
            $this->console->fail($e->getMessage());
        }
    }

    /**
     * @param list<string> $commandArgs
     * @param array<string, bool|string> $options
     */
    private function handleCommand(string $command, array $commandArgs, array $options): int
    {
        $this->commandFactory()->createHandler($command)->handle($commandArgs, $options);

        return 0;
    }

    /**
     * @param list<string> $args
     * @return array{0: list<string>, 1: array<string, bool|string>}
     */
    private function parseArgs(array $args): array
    {
        $parsedArgs = [];
        $options = [];

        for ($i = 0, $c = count($args); $i < $c; $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--')) {
                $option = substr($arg, 2);
                if (str_contains($option, '=')) {
                    [$key, $val] = explode('=', $option, 2);
                    $options[$key] = $val;

                    continue;
                }

                $key = $option;
                $val = $args[$i + 1] ?? true;
                if ($val !== true && str_starts_with((string) $val, '--')) {
                    $val = true;
                } else {
                    $i++;
                }
                $options[$key] = $val;
            } else {
                $parsedArgs[] = $arg;
            }
        }

        return [$parsedArgs, $options];
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function configureTestFileOverrides(array $options): void
    {
        if (!($options[BacklogCliOption::TEST_MODE->value] ?? false)) {
            return;
        }

        if (isset($options[BacklogCliOption::BOARD_FILE->value])) {
            $this->boardPath = $this->validateTestFileOverride((string) $options[BacklogCliOption::BOARD_FILE->value], BacklogCliOption::BOARD_FILE->value);
        }

        if (isset($options[BacklogCliOption::REVIEW_FILE->value])) {
            $this->reviewFilePath = $this->validateTestFileOverride((string) $options[BacklogCliOption::REVIEW_FILE->value], BacklogCliOption::REVIEW_FILE->value);
        }

        if (isset($options[BacklogCliOption::WORKTREE_DIR->value])) {
            $this->worktreesRoot = $this->validateTestFileOverride((string) $options[BacklogCliOption::WORKTREE_DIR->value], BacklogCliOption::WORKTREE_DIR->value);
        }
    }

    private function validateTestFileOverride(string $path, string $option): string
    {
        if (!str_starts_with($path, 'local/tmp/')) {
            throw new \RuntimeException("Backlog test file override for --{$option} must be in local/tmp/.");
        }

        return $this->projectRoot . '/' . $path;
    }

    private function printCommandHelp(string $command): void
    {
        $this->printYamlCommandHelp($command);
    }

    private function commandFactory(): BacklogCommandFactory
    {
        if ($this->commandFactory === null) {
            $this->commandFactory = new BacklogCommandFactory(
                $this->app,
                $this->console,
                $this->dryRun,
                $this->verbose,
                $this->projectRoot,
                $this->resolvedWorktreesRoot(),
                $this->boardPath(),
                $this->reviewFilePath()
            );
        }

        return $this->commandFactory;
    }

    private function optionValidator(): BacklogCliOptionValidator
    {
        if ($this->optionValidator === null) {
            $this->optionValidator = new BacklogCliOptionValidator(
                new CommandHelpService($this->projectRoot . '/scripts/resources'),
            );
        }

        return $this->optionValidator;
    }

    private function initializeLocalFiles(): void
    {
        if ($this->dryRun) {
            return;
        }

        $localDir = dirname($this->boardPath());
        if (!is_dir($localDir)) {
            if ($this->verbose) {
                $this->console->line("Creating local directory: {$localDir}");
            }
            if (mkdir($localDir, 0755, true) === false) {
                throw new \RuntimeException("Failed to create local directory: {$localDir}");
            }
        }

        if (!is_file($this->boardPath())) {
            if ($this->verbose) {
                $this->console->line("Creating backlog board file: {$this->boardPath()}");
            }
            if (file_put_contents($this->boardPath(), self::INITIAL_BOARD_CONTENT) === false) {
                throw new \RuntimeException("Failed to create backlog board file: {$this->boardPath()}");
            }
        }

        if (!is_file($this->reviewFilePath())) {
            if ($this->verbose) {
                $this->console->line("Creating backlog review file: {$this->reviewFilePath()}");
            }
            if (file_put_contents($this->reviewFilePath(), self::INITIAL_REVIEW_CONTENT) === false) {
                throw new \RuntimeException("Failed to create backlog review file: {$this->reviewFilePath()}");
            }
        }
    }

    private function boardPath(): string
    {
        return $this->boardPath ?? ($this->projectRoot . '/' . self::DEFAULT_BOARD_PATH);
    }

    private function reviewFilePath(): string
    {
        return $this->reviewFilePath ?? ($this->projectRoot . '/' . self::DEFAULT_REVIEW_FILE_PATH);
    }

    private function resolvedWorktreesRoot(): string
    {
        if ($this->worktreesRoot !== null) {
            return $this->worktreesRoot;
        }

        $legacy = $this->projectRoot . '/' . self::LEGACY_WORKTREES_DIR;
        $new = $this->projectRoot . '/' . self::DEFAULT_WORKTREES_DIR;

        if (!is_dir($legacy)) {
            return $new;
        }

        if (is_dir($new)) {
            return $new;
        }

        // Use legacy root as long as it still contains active git worktrees
        $porcelain = (string) shell_exec('git worktree list --porcelain 2>/dev/null');
        if (str_contains($porcelain, $legacy . '/')) {
            return $legacy;
        }

        // No active worktrees remain in legacy root: new worktrees go to the new directory.
        // The legacy directory is left untouched.
        return $new;
    }
}
