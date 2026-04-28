<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Backlog\BacklogCommandFactory;
use SoManAgent\Script\Backlog\Enum\BacklogCliOption;
use SoManAgent\Script\Backlog\Enum\BacklogCommandName;
use SoManAgent\Script\Backlog\Service\BacklogHelpService;

/**
 * Local backlog workflow orchestrator.
 */
final class BacklogRunner extends AbstractScriptRunner
{
    private const DEFAULT_BOARD_PATH = 'local/backlog-board.md';
    private const DEFAULT_REVIEW_FILE_PATH = 'local/backlog-review.md';

    private ?string $boardPath = null;
    private ?string $reviewFilePath = null;

    private ?BacklogHelpService $helpService = null;
    private ?BacklogCommandFactory $commandFactory = null;

    protected function getDescription(): string
    {
        return 'Local backlog workflow orchestrator.';
    }

    protected function getCommands(): array
    {
        return $this->helpService()->getCommands();
    }

    protected function getOptions(): array
    {
        return $this->helpService()->getOptions($this->getExecutionModeOptions());
    }

    protected function getUsageExamples(): array
    {
        return $this->helpService()->getUsageExamples();
    }

    /**
     * Executes one backlog workflow command.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        [$parsedArgs, $options] = $this->parseArgs($args);
        $command = array_shift($parsedArgs) ?? '';
        $commandArgs = $parsedArgs;
        $this->configureExecutionModes($options);
        $this->configureTestFileOverrides($options);

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

        if (isset($options['help'])) {
            $this->printCommandHelp($command);

            return 0;
        }

        try {
            return $this->handleCommand($command, $commandArgs, $options);
        } catch (\Exception $e) {
            $this->console->fail($e->getMessage());

            return 1;
        }
    }

    private function handleCommand(string $command, array $commandArgs, array $options): int
    {
        $this->commandFactory()->createHandler($command)->handle($commandArgs, $options);

        return 0;
    }

    private function parseArgs(array $args): array
    {
        $parsedArgs = [];
        $options = [];

        for ($i = 0, $c = count($args); $i < $c; $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--')) {
                $key = ltrim($arg, '-');
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
        echo $this->helpService()->renderCommandHelp($command);
    }

    private function helpService(): BacklogHelpService
    {
        if ($this->helpService === null) {
            $this->helpService = new BacklogHelpService($this->commandFactory()->getFilesystemClient());
        }

        return $this->helpService;
    }

    private function commandFactory(): BacklogCommandFactory
    {
        if ($this->commandFactory === null) {
            $this->commandFactory = new BacklogCommandFactory(
                $this->app,
                $this->console,
                $this->dryRun,
                $this->projectRoot,
                $this->boardPath(),
                $this->reviewFilePath()
            );
        }

        return $this->commandFactory;
    }

    private function boardPath(): string
    {
        return $this->boardPath ?? ($this->projectRoot . '/' . self::DEFAULT_BOARD_PATH);
    }

    private function reviewFilePath(): string
    {
        return $this->reviewFilePath ?? ($this->projectRoot . '/' . self::DEFAULT_REVIEW_FILE_PATH);
    }
}
