<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Application;
use SoManAgent\Script\Console;

/**
 * Abstract base class for all SoManAgent CLI scripts.
 *
 * Provides:
 *  - Automatic help display for `-h` / `--help`
 *  - Standardized bootstrap (Application + Console)
 *  - Consistent error handling and exit codes
 *
 * Usage:
 *   class HealthRunner extends AbstractScriptRunner
 *   {
 *       protected function getDescription(): string
 *       {
 *           return 'Check application health';
 *       }
 *
 *       protected function getUsageExamples(): array
 *       {
 *           return [
 *               'php scripts/health.php',
 *               'php scripts/health.php --url http://localhost:8080',
 *           ];
 *       }
 *
 *       public function run(array $args): int
 *       {
 *           // implementation
 *           return 0;
 *       }
 *   }
 */
abstract class AbstractScriptRunner
{
    protected Application $app;
    protected Console $console;
    protected string $projectRoot;

    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__, 3);
		$this->app     = Application::getInstance();
		$this->console = Console::getInstance();
    }

    /**
     * Entry point — called after help check and bootstrap.
     *
     * @param array<string> $args   CLI arguments with script name and help flags removed
     * @return int                  Exit code (0 = success, non-zero = failure)
     */
    abstract public function run(array $args): int;

    /**
     * Short description displayed in help header.
     */
    abstract protected function getDescription(): string;

    /**
     * Usage examples displayed in help body.
     *
     * @return array<string>
     */
    abstract protected function getUsageExamples(): array;

    /**
     * Declare positional arguments for help display.
     *
     * Override in subclasses to show argument descriptions in help output.
     *
     * @return array<array{name: string, description: string}>
     */
    protected function getArguments(): array
    {
        return [];
    }

    /**
     * Declare options for help display.
     *
     * Override in subclasses to show option descriptions in help output.
     *
     * @return array<array{name: string, description: string}>
     */
    protected function getOptions(): array
    {
        return [];
    }

    /**
     * Declare subcommands for help display.
     *
     * Override in subclasses to show command descriptions in help output.
     *
     * @return array<array{name: string, description: string}>
     */
    protected function getCommands(): array
    {
        return [];
    }

    /**
     * Bootstrap the application, change to project directory, and dispatch.
     *
     * @param array<string> $argv   Raw $argv from the calling script
     */
    final public function handle(array $argv): void
    {
        $args = array_slice($argv, 1);

        if (in_array('-h', $args, true) || in_array('--help', $args, true)) {
            $this->printHelp();
            exit(0);
        }

        try {
            chdir($this->projectRoot);

            $exitCode = $this->run($args);
            exit($exitCode);
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        }
    }

    /**
     * Display the help message (description + commands + arguments + options + usage examples).
     */
    final protected function printHelp(): void
    {
        echo $this->getDescription() . "\n";

        $commands = $this->getCommands();
        if ($commands) {
            echo "\nCommands:\n";
            foreach ($commands as $cmd) {
                echo "  {$cmd['name']}\n";
                echo "    {$cmd['description']}\n";
            }
        }

        $arguments = $this->getArguments();
        if ($arguments) {
            echo "\nArguments:\n";
            foreach ($arguments as $arg) {
                echo "  {$arg['name']}\n";
                echo "    {$arg['description']}\n";
            }
        }

        $options = $this->getOptions();
        if ($options) {
            echo "\nOptions:\n";
            foreach ($options as $opt) {
                echo "  {$opt['name']}\n";
                echo "    {$opt['description']}\n";
            }
        }

        $examples = $this->getUsageExamples();
        if ($examples) {
            echo "\nExamples:\n";
            foreach ($examples as $example) {
                echo "  {$example}\n";
            }
        }
    }
}
