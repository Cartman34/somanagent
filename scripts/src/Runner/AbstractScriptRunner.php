<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

use SoManAgent\Script\Application;
use SoManAgent\Script\Console;
use SoManAgent\Script\Service\CommandHelp;
use SoManAgent\Script\Service\CommandHelpService;
use SoManAgent\Script\Service\CommandParamHelp;
use SoManAgent\Script\Service\RunnerHelp;

/**
 * Abstract base class for all SoManAgent CLI scripts.
 *
 * Provides:
 *  - Automatic help display for bare `help` / `-h` / `--help`
 *  - Standardized bootstrap (Application + Console)
 *  - Consistent error handling and exit codes
 *
 * Two help modes are supported:
 *  - Array-based: override getDescription(), getUsageExamples(), getOptions(), etc.
 *  - YAML-based: override printHelp() to call printYamlHelp(), and store help under
 *    scripts/resources/{name}/help.yaml + scripts/resources/{name}/commands/{cmd}.yaml.
 *
 * @see AbstractScriptRunner::printYamlHelp()       for the YAML-driven help mode
 * @see AbstractScriptRunner::printYamlCommandHelp() for per-command YAML help rendering
 */
abstract class AbstractScriptRunner
{
    protected Application $app;
    protected Console $console;
    protected string $projectRoot;
    protected ?string $scriptFile = null;

    /**
     * Global dry-run flag shared by runners that opt into execution modes.
     *
     * When true, the runner must avoid every mutation it controls:
     * file writes, git writes, GitHub writes, and similar side effects.
     * Read-only inspection may still happen when it is useful and safe.
     */
    protected bool $dryRun = false;

    /**
     * Global verbose flag shared by runners that opt into execution modes.
     *
     * Use it for optional execution traces and simulated command details.
     * Keep user-facing outcome messages independent from this flag so
     * commands still remain understandable without verbose mode.
     */
    protected bool $verbose = false;

    private ?CommandHelpService $helpService = null;

    private ?RunnerHelp $runnerHelp = null;

    /**
     * Initializes the shared application and console singletons and resolves the project root.
     */
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
     * Short stable name identifying the runner — used to locate its YAML resources at
     * scripts/resources/{name}/. Typically matches the script filename without `.php`.
     */
    abstract protected function getName(): string;

    /**
     * Short description displayed in help header.
     *
     * Array-based runners must override; YAML-based runners use printYamlHelp() and never call this.
     */
    protected function getDescription(): string
    {
        // Throws to surface any forgotten override on array-based runners (YAML runners override printHelp).
        throw new \RuntimeException(sprintf(
            'Runner %s did not override getDescription() — required for array-based printHelp(). '
            . 'YAML-based runners should override printHelp() to call printYamlHelp() instead.',
            static::class,
        ));
    }

    /**
     * Usage examples displayed in help body.
     *
     * Array-based runners must override; YAML-based runners use printYamlHelp() and never call this.
     *
     * @return array<string>
     */
    protected function getUsageExamples(): array
    {
        // Throws to surface any forgotten override on array-based runners (YAML runners override printHelp).
        throw new \RuntimeException(sprintf(
            'Runner %s did not override getUsageExamples() — required for array-based printHelp(). '
            . 'YAML-based runners should override printHelp() to call printYamlHelp() instead.',
            static::class,
        ));
    }

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
     * Shared execution-mode options available to runners that opt into them.
     *
     * @return array<array{name: string, description: string}>
     */
    protected function getExecutionModeOptions(): array
    {
        return [
            ['name' => '--dry-run', 'description' => 'Simulate mutations without executing them'],
            ['name' => '--verbose', 'description' => 'Print detailed execution steps and simulated commands'],
            ['name' => '--no-verbose', 'description' => 'Disable verbose output even when --dry-run is enabled'],
        ];
    }

    /**
     * Configures shared execution flags from parsed CLI options.
     *
     * @param array<string, mixed> $options
     */
    protected function configureExecutionModes(array $options): void
    {
        $this->dryRun = isset($options['dry-run']);
        $this->verbose = !isset($options['no-verbose']) && ($this->dryRun || isset($options['verbose']));
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

        if (
            count($args) === 1
            && (($args[0] ?? null) === 'help' || ($args[0] ?? null) === '-h' || ($args[0] ?? null) === '--help')
        ) {
            $this->printHelp();
            exit(0);
        }

        try {
            $this->scriptFile = realpath($argv[0] ?? '') ?: null;
            chdir($this->projectRoot);

            $exitCode = $this->run($args);
            exit($exitCode);
        } catch (\RuntimeException $e) {
            $this->console->fail($e->getMessage());
        }
    }

    /**
     * Standard argument parser for all SoManAgent scripts.
     *
     * Extracts named options (--key or --key=val) and positional arguments.
     * When an option is followed by a value that does not start with --, it is taken as the value.
     * When the same option is used multiple times, values are collected into an array.
     *
     * @param list<string> $args
     * @return array{0: list<string>, 1: array<string, bool|string|array<bool|string>>}
     */
    protected function parseArgs(array $args): array
    {
        $parsedArgs = [];
        $options = [];

        for ($i = 0, $c = count($args); $i < $c; $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--')) {
                $option = substr($arg, 2);
                if (str_contains($option, '=')) {
                    [$key, $val] = explode('=', $option, 2);
                } else {
                    $key = $option;
                    $val = $args[$i + 1] ?? true;
                    if ($val !== true && str_starts_with((string) $val, '--')) {
                        $val = true;
                    } else {
                        $i++;
                    }
                }

                if (isset($options[$key])) {
                    if (is_array($options[$key])) {
                        $options[$key][] = $val;
                    } else {
                        $options[$key] = [$options[$key], $val];
                    }
                } else {
                    $options[$key] = $val;
                }

                continue;
            }

            $parsedArgs[] = $arg;
        }

        return [$parsedArgs, $options];
    }

    /**
     * Convert the execution-mode options to typed CommandParamHelp DTOs.
     *
     * @return list<CommandParamHelp>
     */
    protected function getExecutionModeOptionsAsDtos(): array
    {
        return array_values(array_map(
            static fn(array $opt): CommandParamHelp => new CommandParamHelp($opt['name'], $opt['description']),
            $this->getExecutionModeOptions(),
        ));
    }

    /**
     * Lazy getter for the YAML-driven help service shared by runners.
     */
    protected function getHelpService(): CommandHelpService
    {
        if ($this->helpService === null) {
            $this->helpService = new CommandHelpService($this->projectRoot . '/scripts/resources');
        }

        return $this->helpService;
    }

    /**
     * Lazy getter caching the runner help DTO for the current runner.
     */
    protected function getRunnerHelp(): RunnerHelp
    {
        if ($this->runnerHelp === null) {
            $this->runnerHelp = $this->getHelpService()->getRunnerHelp($this->getName());
        }

        return $this->runnerHelp;
    }

    /**
     * Display the help message from a YAML-defined runner — fetches RunnerHelp + each CommandHelp
     * via the service and renders them. Used by runners that store their help under
     * scripts/resources/{name}/.
     */
    protected function printYamlHelp(): void
    {
        $service    = $this->getHelpService();
        $runnerName = $this->getName();
        $runnerHelp = $this->getRunnerHelp();
        $commands   = array_map(
            fn(string $name): CommandHelp => $service->getCommandHelp($runnerName, $name),
            $runnerHelp->commandNames,
        );

        echo $runnerHelp->description . "\n";

        if ($commands) {
            echo "\nCommands:\n";
            foreach ($commands as $cmd) {
                echo "  {$cmd->name}\n";
                echo "    {$cmd->description}\n";
            }
        }

        $allOptions = array_merge($runnerHelp->options, $this->getExecutionModeOptionsAsDtos());
        if ($allOptions) {
            echo "\nOptions:\n";
            foreach ($allOptions as $opt) {
                echo "  {$opt->name}\n";
                echo "    {$opt->description}\n";
            }
        }

        if ($runnerHelp->examples) {
            echo "\nExamples:\n";
            foreach ($runnerHelp->examples as $example) {
                echo "  {$example}\n";
            }
        }
    }

    /**
     * Render and print the per-command help for a YAML-driven runner.
     */
    protected function printYamlCommandHelp(string $commandName): void
    {
        echo $this->getHelpService()->renderCommandHelp(
            $this->getName(),
            $commandName,
            $this->getExecutionModeOptionsAsDtos(),
        );
    }

    /**
     * Display the help message (description + commands + arguments + options + usage examples).
     *
     * Default implementation reads from the array-based getXxx() methods. Override this method
     * to call printYamlHelp() when the runner provides DTOs from YAML resources.
     */
    protected function printHelp(): void
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
