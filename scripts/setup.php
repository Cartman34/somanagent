#!/usr/bin/env php
<?php
// Description: Full setup of SoManAgent (first run)
// Usage: php scripts/setup.php
// Usage: php scripts/setup.php --skip-frontend

require_once __DIR__ . '/_bootstrap.php';

$root = dirname(__DIR__);
chdir($root);

$skipFrontend = in_array('--skip-frontend', $argv, true);

function step(string $label): void {
    echo "\n▶ $label...\n";
}

function ok(string $msg): void {
    echo "  ✓ $msg\n";
}

function fail(string $msg, int $code = 1): never {
    echo "  ❌ $msg\n";
    exit($code);
}

function run(string $cmd, bool $failOnError = true): int {
    passthru($cmd, $code);
    if ($failOnError && $code !== 0) {
        fail("Command failed: $cmd", $code);
    }
    return $code;
}

echo str_repeat('═', 50) . "\n";
echo "     SoManAgent — Initial setup\n";
echo str_repeat('═', 50) . "\n";

// PHP version check
step('Checking PHP version');
run('bash scripts/check-php.sh');

// .env
step('Checking .env file');
if (!file_exists("$root/.env")) {
    copy("$root/.env.example", "$root/.env");
    ok('.env created from .env.example — fill in the values before continuing.');
    echo "  → Edit .env then re-run this script.\n";
    exit(0);
} else {
    ok('.env already present');
}

// Docker
step('Starting Docker containers');
run('docker compose up -d --build');
ok('Containers started');

// Wait for PostgreSQL — poll Docker healthcheck status (cross-platform)
step('Waiting for PostgreSQL');
$tries = 0;
do {
    sleep(1);
    $tries++;
    exec('docker inspect --format={{.State.Health.Status}} somanagent_db 2>&1', $out, $code);
    $status = trim($out[0] ?? '');
    $out = [];
} while ($status !== 'healthy' && $tries < 30);

if ($status !== 'healthy') {
    fail('PostgreSQL did not become healthy after 30 seconds. Run: docker compose logs db');
}
ok("PostgreSQL ready ({$tries}s)");

// Migrations
step('Running Doctrine migrations');
run('php scripts/console.php doctrine:migrations:migrate --no-interaction');
ok('Migrations complete');

// Frontend
if (!$skipFrontend) {
    step('Installing frontend dependencies (npm)');
    run('docker compose exec -T node npm install');
    ok('npm install done');
} else {
    echo "  → Frontend skipped (--skip-frontend)\n";
}

echo "\n" . str_repeat('═', 50) . "\n";
echo "  ✓ SoManAgent is ready!\n\n";
echo "  API  →  http://localhost:8080/api/health\n";
echo "  UI   →  http://localhost:5173\n";
echo str_repeat('═', 50) . "\n\n";
