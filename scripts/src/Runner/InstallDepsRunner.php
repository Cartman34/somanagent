<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * System dependency manager for Ubuntu 24+.
 *
 * Provides two subcommands:
 *  - check:   verify that each dependency in the manifest is installed and meets the minimum version
 *  - install: install missing or outdated dependencies via apt in transitive dependency order
 *
 * The manifest is the single source of truth for which packages are required and at what
 * minimum version. Add a new entry to MANIFEST to extend coverage to a new dependency.
 * Transitive dependencies are resolved by listing other manifest keys in the `requires` field.
 */
final class InstallDepsRunner extends AbstractScriptRunner
{
    public const NAME = 'install-deps';

    /**
     * Declarative dependency manifest.
     *
     * Each entry declares:
     *   package        – apt package name to install
     *   minVersion     – minimum acceptable version string (compared after stripping trailing alpha suffix)
     *   checkCommand   – shell command whose stdout contains the installed version
     *   versionPattern – PCRE pattern with one capture group extracting the raw version string
     *   requires       – list of other manifest keys that must be installed first (transitive deps)
     *
     * @var array<string, array{
     *   package: string,
     *   minVersion: string,
     *   checkCommand: string,
     *   versionPattern: string,
     *   requires: list<string>,
     * }>
     */
    private const MANIFEST = [
        'tmux' => [
            'package'        => 'tmux',
            'minVersion'     => '3.2',
            'checkCommand'   => 'tmux -V',
            'versionPattern' => '/^tmux\s+(\S+)/',
            'requires'       => [],
        ],
    ];

    /**
     * {@inheritdoc}
     */
    protected function getName(): string
    {
        return self::NAME;
    }

    /**
     * {@inheritdoc}
     */
    protected function printHelp(): void
    {
        $this->printYamlHelp();
    }

    /**
     * Parses the subcommand and dispatches to check or install.
     *
     * @param array<string> $args
     */
    public function run(array $args): int
    {
        [$parsedArgs, $options] = $this->parseArgs(array_values($args));
        $command = array_shift($parsedArgs) ?? '';

        if (isset($options['help'])) {
            if ($command !== '') {
                $this->printYamlCommandHelp($command);
            } else {
                $this->printYamlHelp();
            }

            return 0;
        }

        if ($command === '') {
            $this->printYamlHelp();

            return 0;
        }

        if ($command === 'check') {
            return $this->runCheck();
        }

        if ($command === 'install') {
            return $this->runInstall();
        }

        $this->console->fail(sprintf(
            'Unknown command: %s. Run with --help for the list of available commands.',
            $command,
        ));
    }

    /**
     * Checks each manifest dependency and reports ok, missing, or version-insufficient.
     *
     * Exits with code 0 when all dependencies are satisfied, code 1 when any is missing or outdated.
     * When issues are found, prints the apt commands required to resolve them.
     */
    private function runCheck(): int
    {
        $ordered = $this->resolveDependencyOrder();
        $statuses = [];

        $this->console->line('Checking system dependencies...');
        $this->console->line();

        foreach ($ordered as $key) {
            $status = $this->checkDependency(self::MANIFEST[$key]);
            $statuses[$key] = $status;

            $icon = $status['ok'] ? '✓' : '✗';
            $this->console->line(sprintf('  %s  %-12s  %s', $icon, $key, $status['message']));
        }

        $issues = array_filter($statuses, static fn(array $s): bool => !$s['ok']);

        if ($issues === []) {
            $this->console->line();
            $this->console->ok('All dependencies are satisfied.');

            return 0;
        }

        $packages = array_map(
            static fn(string $key): string => self::MANIFEST[$key]['package'],
            array_keys($issues),
        );

        $this->console->line();
        $this->console->warn(sprintf('%d dependency issue(s) detected.', count($issues)));
        $this->console->line();
        $this->console->line('Run the following commands to resolve them:');
        $this->console->line('  sudo apt-get update');
        $this->console->line('  sudo apt-get install -y ' . implode(' ', $packages));

        return 1;
    }

    /**
     * Installs missing or outdated dependencies via apt in transitive dependency order.
     *
     * Skips all apt calls when every dependency is already satisfied.
     * Runs sudo apt-get update before installing any package.
     */
    private function runInstall(): int
    {
        $ordered = $this->resolveDependencyOrder();
        $toInstall = [];

        foreach ($ordered as $key) {
            $status = $this->checkDependency(self::MANIFEST[$key]);
            if (!$status['ok']) {
                $toInstall[] = self::MANIFEST[$key]['package'];
            }
        }

        if ($toInstall === []) {
            $this->console->ok('All dependencies are already installed.');

            return 0;
        }

        $this->console->step('Updating package index');
        $updateCode = $this->app->runCommand('sudo apt-get update -qq');
        if ($updateCode !== 0) {
            throw new \RuntimeException(sprintf('apt-get update failed (exit %d).', $updateCode));
        }

        $packageList = implode(' ', array_map('escapeshellarg', $toInstall));
        $this->console->step(sprintf('Installing: %s', implode(', ', $toInstall)));
        $installCode = $this->app->runCommand(
            'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y ' . $packageList,
        );
        if ($installCode !== 0) {
            throw new \RuntimeException(sprintf('apt-get install failed (exit %d).', $installCode));
        }

        $this->console->line();
        $this->console->ok('Installation complete.');

        return 0;
    }

    /**
     * Checks a single manifest dependency and returns its status.
     *
     * Runs the configured check command, parses the version from its output, and
     * compares it against the minimum version after stripping any trailing alpha suffix.
     *
     * @param array{package: string, minVersion: string, checkCommand: string, versionPattern: string, requires: list<string>} $config
     * @return array{ok: bool, message: string}
     */
    private function checkDependency(array $config): array
    {
        $output = shell_exec($config['checkCommand'] . ' 2>/dev/null');

        if (!is_string($output) || trim($output) === '') {
            return ['ok' => false, 'message' => 'not installed'];
        }

        if (!preg_match($config['versionPattern'], trim($output), $matches)) {
            return ['ok' => false, 'message' => 'not installed (unrecognized version output)'];
        }

        $rawVersion = $matches[1];
        $numericVersion = rtrim($rawVersion, 'abcdefghijklmnopqrstuvwxyz');

        if (version_compare($numericVersion, $config['minVersion'], '<')) {
            return [
                'ok'      => false,
                'message' => sprintf(
                    'version %s installed, >= %s required',
                    $rawVersion,
                    $config['minVersion'],
                ),
            ];
        }

        return ['ok' => true, 'message' => sprintf('version %s', $rawVersion)];
    }

    /**
     * Returns manifest keys in topological dependency order using depth-first search.
     *
     * Dependencies listed in `requires` are guaranteed to appear before their dependants.
     * Circular dependencies are avoided because requires must reference other manifest keys
     * and the manifest is a finite, acyclic structure by design.
     *
     * @return list<string>
     */
    private function resolveDependencyOrder(): array
    {
        $result = [];
        $visited = [];

        foreach (array_keys(self::MANIFEST) as $key) {
            $this->visitDependency($key, $visited, $result);
        }

        return $result;
    }

    /**
     * Recursive DFS visitor for transitive dependency resolution.
     *
     * @param array<string, bool> $visited
     * @param list<string>        $result
     */
    private function visitDependency(string $key, array &$visited, array &$result): void
    {
        if (isset($visited[$key])) {
            return;
        }

        $visited[$key] = true;

        /** @phpstan-ignore foreach.emptyArray */
        foreach (self::MANIFEST[$key]['requires'] as $required) {
            $this->visitDependency($required, $visited, $result);
        }

        $result[] = $key;
    }
}
