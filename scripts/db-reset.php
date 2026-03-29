#!/usr/bin/env php
<?php
// Description: Recreate the local database, run migrations, and optionally reload fixtures
// Usage: php scripts/db-reset.php
// Usage: php scripts/db-reset.php --fixtures
// Usage: php scripts/db-reset.php --fixtures --force

require_once __DIR__ . '/src/Application.php';

try {
    $app = new Application();
    $app->boot();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "\n❌ " . $e->getMessage() . "\n\n");
    exit(1);
}

$c = $app->console;
$root = dirname(__DIR__);
chdir($root);

$withFixtures = in_array('--fixtures', $argv, true);
$force = in_array('--force', $argv, true);

if (!$force) {
    $target = $withFixtures
        ? 'This will recreate the local database and reload fixtures.'
        : 'This will recreate the local database.';

    $c->warn($target);
    $c->warn('All current local data will be lost.');
    $c->line('  Type "yes" to continue:');

    $confirmation = trim((string) fgets(STDIN));
    if ($confirmation !== 'yes') {
        $c->fail('Aborted.', 0);
    }
}

try {
    $c->step('Starting required containers');
    $code = $app->runCommand('docker compose up -d db php');
    if ($code !== 0) {
        throw new \RuntimeException("docker compose up failed (exit $code).");
    }

    $c->step('Dropping local database');
    $code = $app->runCommand('docker compose exec -T php php bin/console doctrine:database:drop --if-exists --force');
    if ($code !== 0) {
        throw new \RuntimeException("Database drop failed (exit $code).");
    }

    $c->step('Creating local database');
    $code = $app->runCommand('docker compose exec -T php php bin/console doctrine:database:create');
    if ($code !== 0) {
        throw new \RuntimeException("Database creation failed (exit $code).");
    }

    $c->step('Running migrations');
    $code = $app->runCommand('docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction');
    if ($code !== 0) {
        throw new \RuntimeException("Migration command failed (exit $code).");
    }

    if ($withFixtures) {
        $c->step('Reloading fixtures');
        $code = $app->runCommand('docker compose exec -T php php bin/console doctrine:fixtures:load --no-interaction');
        if ($code !== 0) {
            throw new \RuntimeException("Fixtures load failed (exit $code).");
        }
    }
} catch (\RuntimeException $e) {
    $c->fail($e->getMessage());
}

$c->ok($withFixtures ? 'Database recreated and fixtures reloaded.' : 'Database recreated.');
