#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run Doctrine migrations inside the PHP container
// Usage: php scripts/migrate.php
// Usage: php scripts/migrate.php --dry-run

if (in_array('-h', $argv, true) || in_array('--help', $argv, true)) {
    echo "Run Doctrine migrations inside the PHP container\n\n";
    echo "Usage: php scripts/migrate.php\n";
    echo "Usage: php scripts/migrate.php --dry-run\n";
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

$dryRun = in_array('--dry-run', $argv, true);

$c->step('Doctrine migrations' . ($dryRun ? ' (dry-run)' : ''));

try {
    $args = ['doctrine:migrations:migrate', '--no-interaction'];
    if ($dryRun) {
        $args[] = '--dry-run';
    }

    // -T: disable pseudo-TTY allocation (non-interactive command through a pipe)
    $escapedArgs = implode(' ', array_map('escapeshellarg', $args));
    $code        = $app->runCommand("docker compose exec -T php php bin/console $escapedArgs");

    if ($code !== 0) {
        throw new \RuntimeException("Migration command failed (exit $code).");
    }

    $c->ok('Migrations complete.');
} catch (\RuntimeException $e) {
    $c->fail($e->getMessage());
}
