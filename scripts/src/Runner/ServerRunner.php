<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace SoManAgent\Script\Runner;

/**
 * Docker Compose service manager for the SoManAgent development environment.
 *
 * Manages starting, stopping, restarting, and health-checking the Docker services
 * that back the local development environment. Help is driven by YAML resources
 * under scripts/resources/server/.
 *
 * Docker Compose profiles:
 *   - no profile (always started): db, redis
 *   - profile 'full': php, worker, nginx, node, mercure
 *
 * Execution modes available on mutation commands (start, stop, restart):
 *   --preview-only  Show the planned operation and exit without applying
 *   --dry-run       Show the planned operation and simulated commands, then exit without applying
 *   --force         Apply without confirmation (preview still printed)
 */
final class ServerRunner extends AbstractScriptRunner
{
    public const NAME = 'server';

    private const SERVICES_FULL = 'php, worker, nginx, node, mercure, db, redis';
    private const SERVICES_MINIMAL = 'db, redis';

    private bool $previewOnly = false;

    protected function getName(): string
    {
        return self::NAME;
    }

    protected function printHelp(): void
    {
        $this->printYamlHelp();
    }

    /**
     * Execution-mode options are declared per-command in YAML; suppress the base-class defaults.
     *
     * @return list<never>
     */
    protected function getExecutionModeOptionsAsDtos(): array
    {
        return [];
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args): int
    {
        [$parsedArgs, $options] = $this->parseArgs($args);
        $subcommand = array_shift($parsedArgs) ?? '';

        if ($subcommand === '' || $subcommand === 'help') {
            $target = $parsedArgs[0] ?? '';
            if ($target !== '') {
                $this->printYamlCommandHelp($target);

                return 0;
            }
            $this->printHelp();

            return 0;
        }

        if (isset($options['help'])) {
            $this->printYamlCommandHelp($subcommand);

            return 0;
        }

        $this->previewOnly = isset($options['preview-only']);
        $this->dryRun = isset($options['dry-run']);

        if ($this->previewOnly && $this->dryRun) {
            throw new \RuntimeException('--preview-only and --dry-run are mutually exclusive.');
        }

        return match ($subcommand) {
            'start'   => $this->runStart($options),
            'stop'    => $this->runStop($options),
            'restart' => $this->runRestart($options),
            'status'  => $this->runStatus(),
            'health'  => $this->runHealth(),
            default   => throw new \RuntimeException(
                sprintf("Unknown subcommand: '%s'. Run 'php scripts/server.php help' for the list.", $subcommand),
            ),
        };
    }

    /**
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function runStart(array $options): int
    {
        $minimal = isset($options['minimal']);
        $force = isset($options['force']);

        $services = $minimal ? self::SERVICES_MINIMAL : self::SERVICES_FULL;
        $cmd = $this->buildStartCommand($minimal);
        $label = $minimal ? 'Start services (minimal)' : 'Start services (full)';

        $this->console->step('Starting services' . ($minimal ? ' (minimal)' : ''));
        $this->printMutationPreview($label, $services, $cmd);

        if ($this->previewOnly || $this->dryRun) {
            return 0;
        }

        if (!$force && !$this->confirmApply()) {
            return 0;
        }

        $code = $this->app->runCommand($cmd);
        if ($code !== 0) {
            throw new \RuntimeException("docker compose up failed (exit {$code}).");
        }

        $this->console->line();
        if (!$minimal) {
            $this->console->line('  API  →  http://localhost:8080/api/health');
            $this->console->line('  UI   →  http://localhost:5173');
        }
        $this->console->line('  DB   →  localhost:5432  (somanagent / somanagent)');
        $this->console->line();
        $this->console->line('  Logs  : php scripts/logs.php [php|worker|node|db|nginx]');
        $this->console->line('  Stop  : php scripts/server.php stop');
        $this->console->line();

        return 0;
    }

    /**
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function runStop(array $options): int
    {
        $force = isset($options['force']);

        $this->console->step('Stopping services');
        $this->printMutationPreview('Stop all services', 'all', 'docker compose down');

        if ($this->previewOnly || $this->dryRun) {
            return 0;
        }

        if (!$force && !$this->confirmApply()) {
            return 0;
        }

        $code = $this->app->runCommand('docker compose down');
        if ($code !== 0) {
            throw new \RuntimeException("docker compose down failed (exit {$code}).");
        }

        $this->console->ok('Services stopped.');

        return 0;
    }

    /**
     * @param array<string, bool|string|array<bool|string>> $options
     */
    private function runRestart(array $options): int
    {
        $minimal = isset($options['minimal']);
        $force = isset($options['force']);

        $startCmd = $this->buildStartCommand($minimal);
        $services = $minimal ? self::SERVICES_MINIMAL : self::SERVICES_FULL;
        $label = $minimal ? 'Restart services (minimal)' : 'Restart services (full)';

        $this->console->step('Restarting services' . ($minimal ? ' (minimal)' : ''));
        $this->console->line('');
        $this->console->line('  Preview:');
        $this->console->line("    Action  : {$label}");
        $this->console->line("    Services: {$services}");
        $this->console->line('    Step 1  : docker compose down');
        $this->console->line("    Step 2  : {$startCmd}");

        if ($this->dryRun) {
            $this->console->line('');
            $this->console->line('  [dry-run] No changes applied.');
        }
        $this->console->line('');

        if ($this->previewOnly || $this->dryRun) {
            return 0;
        }

        if (!$force && !$this->confirmApply()) {
            return 0;
        }

        $stopCode = $this->app->runCommand('docker compose down');
        if ($stopCode !== 0) {
            throw new \RuntimeException("docker compose down failed (exit {$stopCode}).");
        }

        $startCode = $this->app->runCommand($startCmd);
        if ($startCode !== 0) {
            throw new \RuntimeException("docker compose up failed (exit {$startCode}).");
        }

        $this->console->line();
        if (!$minimal) {
            $this->console->line('  API  →  http://localhost:8080/api/health');
            $this->console->line('  UI   →  http://localhost:5173');
        }
        $this->console->line('  DB   →  localhost:5432  (somanagent / somanagent)');
        $this->console->line();

        return 0;
    }

    private function runStatus(): int
    {
        return $this->app->runCommand('docker compose ps');
    }

    private function runHealth(): int
    {
        $this->console->step('Checking service health');
        $allHealthy = true;

        // PostgreSQL
        $out = [];
        exec('pg_isready -h localhost -p 5432 2>&1', $out, $pgCode);
        $pgMsg = trim(implode(' ', $out));
        if ($pgCode === 0) {
            $this->console->ok("PostgreSQL: {$pgMsg}");
        } else {
            $this->console->line("  \u{274C} PostgreSQL: {$pgMsg}");
            $allHealthy = false;
        }

        // Redis
        $out = [];
        exec('redis-cli -h localhost -p 6379 ping 2>&1', $out, $redisCode);
        $redisMsg = trim(implode(' ', $out));
        if ($redisCode === 0 && strtoupper($redisMsg) === 'PONG') {
            $this->console->ok('Redis: PONG');
        } else {
            $this->console->line("  \u{274C} Redis: {$redisMsg}");
            $allHealthy = false;
        }

        // Full-profile: nginx → API
        if ($this->isContainerRunning('somanagent_nginx')) {
            $this->console->line('');
            $this->console->step('Full-profile services');
            if ($this->httpCheck('http://localhost:8080/api/health', 5)) {
                $this->console->ok('API (nginx): reachable at http://localhost:8080/api/health');
            } else {
                $this->console->line("  \u{274C} API (nginx): not reachable at http://localhost:8080/api/health");
                $allHealthy = false;
            }
        }

        // Full-profile: mercure
        if ($this->isContainerRunning('somanagent_mercure')) {
            if ($this->httpCheck('http://localhost:8080/.well-known/mercure', 5)) {
                $this->console->ok('Mercure: reachable at http://localhost:8080/.well-known/mercure');
            } else {
                $this->console->line("  \u{274C} Mercure: not reachable at http://localhost:8080/.well-known/mercure");
                $allHealthy = false;
            }
        }

        $this->console->line('');
        if ($allHealthy) {
            $this->console->ok('All checked services are healthy.');

            return 0;
        }

        $this->console->line('  Some services are not healthy.');

        return 1;
    }

    private function buildStartCommand(bool $minimal): string
    {
        return $minimal ? 'docker compose up -d' : 'docker compose --profile full up -d';
    }

    private function printMutationPreview(string $action, string $services, string $command): void
    {
        $this->console->line('');
        $this->console->line('  Preview:');
        $this->console->line("    Action  : {$action}");
        $this->console->line("    Services: {$services}");
        $this->console->line("    Command : {$command}");

        if ($this->dryRun) {
            $this->console->line('');
            $this->console->line('  [dry-run] No changes applied.');
        }
        $this->console->line('');
    }

    /**
     * Prompts for confirmation on an interactive terminal.
     *
     * Throws when stdin is not a tty and neither --force, --preview-only nor --dry-run
     * was passed, because there is no safe default for a destructive mutation in non-interactive mode.
     */
    private function confirmApply(): bool
    {
        if (function_exists('posix_isatty') && !posix_isatty(STDIN)) {
            throw new \RuntimeException(
                'Non-interactive: use --force to apply without confirmation, '
                . 'or --preview-only / --dry-run to show the plan without applying.',
            );
        }

        echo '  Apply? [y/N] ';
        $answer = trim((string) (fgets(STDIN) ?: ''));
        if (!preg_match('/^[yY]/', $answer)) {
            $this->console->info('Aborted.');

            return false;
        }

        return true;
    }

    private function isContainerRunning(string $containerName): bool
    {
        $out = [];
        exec('docker inspect --format={{.State.Running}} ' . escapeshellarg($containerName) . ' 2>/dev/null', $out, $code);

        return $code === 0 && trim($out[0] ?? '') === 'true';
    }

    private function httpCheck(string $url, int $timeout = 5): bool
    {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);

        return @file_get_contents($url, false, $ctx) !== false;
    }
}
