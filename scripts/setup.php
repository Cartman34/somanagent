#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Full setup of SoManAgent (first run)
// Usage: php scripts/setup.php
// Usage: php scripts/setup.php --skip-frontend

if (in_array('-h', $argv, true) || in_array('--help', $argv, true)) {
    echo "Full setup of SoManAgent (first run)\n\n";
    echo "Usage: php scripts/setup.php\n";
    echo "Usage: php scripts/setup.php --skip-frontend\n";
    exit(0);
}

require_once __DIR__ . '/src/Application.php';

try {
    $app = new Application();
    $app->boot();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "\n❌ " . $e->getMessage() . "\n\n");
    exit(1);
}

$c    = $app->console;
$root = dirname(__DIR__);
chdir($root);

$skipFrontend = in_array('--skip-frontend', $argv, true);

/** Run a command via Application (CRLF-safe), throw on non-zero exit. */
$run = static function (string $cmd) use ($app): void {
    $code = $app->runCommand($cmd);
    if ($code !== 0) {
        throw new \RuntimeException("Command failed (exit $code): $cmd");
    }
};

$c->hr();
$c->line('     SoManAgent — Initial setup');
$c->hr();

// ── WSL filesystem performance check ───────────────────────────────────────
// Docker bind mounts from /mnt/c/... (Windows NTFS) are 5-20x slower than
// WSL native ext4. Detect and warn early — before the user waits minutes
// wondering why the API is slow.
if (Environment::isOnWindowsFilesystem()) {
    $c->line();
    $c->warn('Performance warning: project is on the Windows filesystem.');
    $c->warn('  Path : ' . getcwd());
    $c->warn('  Docker bind mounts from /mnt/* use the 9P protocol over Hyper-V.');
    $c->warn('  This causes 5-20x slower PHP/DB I/O (e.g. 9s for a simple query).');
    $c->line();
    $c->info('Fix → migrate the project to the WSL native ext4 filesystem:');
    $c->info('  bash scripts/wsl-migrate.sh');
    $c->line();
    $c->info('After migration, run this script again from the new location.');
    $c->info('Your IDE can access WSL files via:');
    $c->info('  \\\\wsl.localhost\\' . (getenv('WSL_DISTRO_NAME') ?: 'Ubuntu') . '\home\<user>\somanagent');
    $c->line();

    // In non-interactive contexts (CI, passthru) stop here.
    // Interactively, give the user a chance to proceed anyway.
    if (!posix_isatty(STDIN)) {
        $c->fail('Aborting: migrate to WSL filesystem first for acceptable performance.');
    }

    echo '  Continue anyway? This will be slow. [y/N] ';
    $answer = trim(fgets(STDIN) ?: '');
    if (!preg_match('/^[yY]/', $answer)) {
        $c->fail('Aborted. Run: bash scripts/wsl-migrate.sh');
    }
    $c->line();
}
// ───────────────────────────────────────────────────────────────────────────

try {
    // PHP version check
    $c->step('Checking PHP version');
    $run('bash scripts/check-php.sh');

    // .env
    $c->step('Checking .env file');
    if (!file_exists("$root/.env")) {
        copy("$root/.env.example", "$root/.env");
        $c->ok('.env created from .env.example');
        $c->warn('Fill in the values in .env then re-run this script.');
        exit(0);
    }
    $c->ok('.env present');

    // Docker
    $c->step('Starting Docker containers');
    $run('docker compose up -d --build');
    $c->ok('Containers started');

    // PHP dependencies — run before waiting for PostgreSQL since it doesn't
    // need the database, and vendor/ must exist before any Symfony command runs.
    $c->step('Installing PHP dependencies (composer)');
    $run('docker compose exec -T php composer install');
    $c->ok('Composer dependencies installed');

    // Wait for PostgreSQL — poll Docker healthcheck (cross-platform, no /dev/null)
    $c->step('Waiting for PostgreSQL');
    $tries  = 0;
    $status = '';
    do {
        sleep(1);
        $tries++;
        exec('docker inspect --format={{.State.Health.Status}} somanagent_db 2>&1', $out, $code);
        $status = trim($out[0] ?? '');
        $out    = [];
    } while ($status !== 'healthy' && $tries < 30);

    if ($status !== 'healthy') {
        throw new \RuntimeException(
            "PostgreSQL did not become healthy after {$tries}s.\n" .
            "  Run: docker compose logs db"
        );
    }
    $c->ok("PostgreSQL ready ({$tries}s)");

    // Migrations
    $c->step('Running Doctrine migrations');
    $run('docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction');
    $c->ok('Migrations complete');

    // Frontend
    if (!$skipFrontend) {
        $c->step('Installing frontend dependencies (npm)');
        $run('docker compose exec -T node npm install');
        $c->ok('npm install done');
    } else {
        $c->info('Frontend skipped (--skip-frontend)');
    }
} catch (\RuntimeException $e) {
    $c->fail($e->getMessage());
}

$c->line();
$c->hr();
$c->line('  ✓ SoManAgent is ready!');
$c->line();
$c->line('  API  →  http://localhost:8080/api/health');
$c->line('  UI   →  http://localhost:5173');
$c->hr();
$c->line();
