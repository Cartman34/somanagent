<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Installs or upgrades AI CLI clients locally (WSL) and optionally inside Docker containers.
 *
 * Client selection: positional arguments pick specific clients; omitting them falls back to
 * the defaults defined in CLIENTS. Pass --docker to also install on the php and worker
 * services. Pass --upgrade to upgrade already-installed clients instead of skipping them.
 */
final class InstallClientsRunner extends AbstractScriptRunner
{
    /**
     * Default client configuration.
     *
     * enabled      – installed when no explicit client selection is given
     * binary       – executable name used to detect whether the client is already present
     * npmPackage   – npm package to install (null for non-npm clients)
     * localInstall – shell command used for a fresh local install
     * localUpgrade – shell command used to upgrade an existing local install
     * dockerInstall – shell command used for a fresh install inside a Docker container
     * dockerUpgrade – shell command used to upgrade inside a Docker container
     *
     * @var array<string, array{
     *   enabled: bool,
     *   binary: string,
     *   npmPackage: string|null,
     *   localInstall: string,
     *   localUpgrade: string,
     *   dockerInstall: string,
     *   dockerUpgrade: string,
     * }>
     */
    private const CLIENTS = [
        'claude' => [
            'enabled'       => true,
            'binary'        => 'claude',
            'npmPackage'    => '@anthropic-ai/claude-code',
            'localInstall'  => 'npm install -g @anthropic-ai/claude-code',
            'localUpgrade'  => 'npm install -g @anthropic-ai/claude-code',
            'dockerInstall' => 'npm install -g @anthropic-ai/claude-code',
            'dockerUpgrade' => 'npm install -g @anthropic-ai/claude-code',
        ],
        'codex' => [
            'enabled'       => true,
            'binary'        => 'codex',
            'npmPackage'    => '@openai/codex',
            'localInstall'  => 'npm install -g @openai/codex',
            'localUpgrade'  => 'npm install -g @openai/codex',
            'dockerInstall' => 'npm install -g @openai/codex',
            'dockerUpgrade' => 'npm install -g @openai/codex',
        ],
        'opencode' => [
            'enabled'       => false,
            'binary'        => 'opencode',
            'npmPackage'    => null,
            'localInstall'  => 'curl -fsSL https://opencode.ai/install | bash',
            'localUpgrade'  => 'opencode upgrade',
            'dockerInstall' => 'curl -fsSL https://opencode.ai/install | bash -s -- --no-modify-path && ln -sf ~/.opencode/bin/opencode /usr/local/bin/opencode',
            'dockerUpgrade' => 'opencode upgrade',
        ],
    ];

    /** Docker Compose services that need CLI clients installed. */
    private const DOCKER_SERVICES = ['php', 'worker'];

    protected function getDescription(): string
    {
        return 'Install or upgrade AI CLI clients locally and/or inside Docker containers';
    }

    protected function getArguments(): array
    {
        return [
            ['name' => 'client...', 'description' => 'Clients to install: claude, codex, opencode (default: enabled ones from config)'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['name' => '--local', 'description' => 'Install locally (combined with --docker to target both)'],
            ['name' => '--docker', 'description' => 'Install inside the php and worker Docker containers (alone = Docker only)'],
            ['name' => '--upgrade', 'description' => 'Upgrade already-installed clients instead of skipping them'],
        ];
    }

    protected function getUsageExamples(): array
    {
        return [
            'php scripts/install-clients.php',
            'php scripts/install-clients.php codex',
            'php scripts/install-clients.php --docker',
            'php scripts/install-clients.php --local --docker',
            'php scripts/install-clients.php codex opencode --local --docker --upgrade',
        ];
    }

    /**
     * Parses arguments, resolves the client list, and dispatches installs to local and/or Docker targets.
     */
    public function run(array $args): int
    {
        $docker  = in_array('--docker', $args, true);
        $local   = !$docker || in_array('--local', $args, true);
        $upgrade = in_array('--upgrade', $args, true);

        $requested = array_values(array_filter(
            $args,
            static fn(string $a): bool => !str_starts_with($a, '--'),
        ));

        if ($requested !== []) {
            $unknown = array_diff($requested, array_keys(self::CLIENTS));
            if ($unknown !== []) {
                throw new \RuntimeException(sprintf(
                    'Unknown client(s): %s. Available: %s.',
                    implode(', ', $unknown),
                    implode(', ', array_keys(self::CLIENTS)),
                ));
            }

            $clients = $requested;
        } else {
            $clients = array_keys(array_filter(
                self::CLIENTS,
                static fn(array $c): bool => $c['enabled'],
            ));
        }

        if ($clients === []) {
            $this->console->warn('No clients selected and none are enabled by default.');
            return 0;
        }

        $targets = [];
        if ($local) {
            $targets[] = 'local';
        }
        if ($docker) {
            array_push($targets, ...self::DOCKER_SERVICES);
        }

        $this->console->line();
        $this->console->info(sprintf('Clients : %s', implode(', ', $clients)));
        $this->console->info(sprintf('Targets : %s', implode(', ', $targets)));
        $this->console->info(sprintf('Mode    : %s', $upgrade ? 'install + upgrade' : 'install (skip if present)'));

        foreach ($clients as $name) {
            $config = self::CLIENTS[$name];

            $this->console->step(sprintf('Client: %s', $name));

            if ($local) {
                $this->installLocal($name, $config, $upgrade);
            }

            if ($docker) {
                foreach (self::DOCKER_SERVICES as $service) {
                    $this->installInDocker($name, $config, $service, $upgrade);
                }
            }
        }

        $this->console->line();
        $this->console->ok('Done.');

        return 0;
    }

    /**
     * Installs or upgrades a client in the local WSL environment.
     *
     * @param array<string, mixed> $config
     */
    private function installLocal(string $name, array $config, bool $upgrade): void
    {
        $binary    = $config['binary'];
        $installed = $this->isInstalledLocally($binary);

        if ($installed) {
            if (!$upgrade) {
                $this->console->warn(sprintf('[local] %s is already installed — skipping (use --upgrade to update).', $name));
                return;
            }

            $this->console->info(sprintf('[local] %s already installed — upgrading.', $name));
            $cmd = $config['localUpgrade'];
        } else {
            $this->console->info(sprintf('[local] Installing %s...', $name));
            $cmd = $config['localInstall'];
        }

        $code = $this->app->runCommand($cmd);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf('[local] %s install/upgrade failed (exit %d).', $name, $code));
        }

        $this->console->ok(sprintf('[local] %s %s.', $name, $installed ? 'upgraded' : 'installed'));
    }

    /**
     * Installs or upgrades a client inside a Docker Compose service.
     *
     * @param array<string, mixed> $config
     */
    private function installInDocker(string $name, array $config, string $service, bool $upgrade): void
    {
        $binary    = $config['binary'];
        $installed = $this->isInstalledInDocker($binary, $service);

        if ($installed) {
            if (!$upgrade) {
                $this->console->warn(sprintf('[%s] %s is already installed — skipping (use --upgrade to update).', $service, $name));
                return;
            }

            $this->console->info(sprintf('[%s] %s already installed — upgrading.', $service, $name));
            $innerCmd = $config['dockerUpgrade'];
        } else {
            $this->console->info(sprintf('[%s] Installing %s...', $service, $name));
            $innerCmd = $config['dockerInstall'];
        }

        $cmd  = sprintf(
            'docker compose exec -T %s sh -lc %s',
            escapeshellarg($service),
            escapeshellarg($innerCmd),
        );
        $code = $this->app->runCommand($cmd);

        if ($code !== 0) {
            throw new \RuntimeException(sprintf('[%s] %s install/upgrade failed (exit %d).', $service, $name, $code));
        }

        $this->console->ok(sprintf('[%s] %s %s.', $service, $name, $installed ? 'upgraded' : 'installed'));
    }

    /**
     * Returns true when the client binary is found in the local shell PATH.
     */
    private function isInstalledLocally(string $binary): bool
    {
        $result = shell_exec(sprintf('which %s 2>/dev/null', escapeshellarg($binary)));

        return $result !== null && trim($result) !== '';
    }

    /**
     * Returns true when the client binary is found in the PATH of the given Docker service.
     */
    private function isInstalledInDocker(string $binary, string $service): bool
    {
        $cmd    = sprintf(
            'docker compose exec -T %s sh -lc %s 2>/dev/null',
            escapeshellarg($service),
            escapeshellarg(sprintf('which %s', escapeshellarg($binary))),
        );
        $result = shell_exec($cmd);

        return $result !== null && trim($result) !== '';
    }
}
