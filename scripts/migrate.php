#!/usr/bin/env php
<?php
// Description: Run Doctrine migrations inside the PHP container
// Usage: php scripts/migrate.php
// Usage: php scripts/migrate.php --dry-run

require_once __DIR__ . '/_bootstrap.php';

$root = dirname(__DIR__);
chdir($root);

$dryRun = in_array('--dry-run', $argv, true);

echo "▶ Doctrine migrations" . ($dryRun ? ' (dry-run)' : '') . "...\n";

$args = ['doctrine:migrations:migrate', '--no-interaction'];
if ($dryRun) {
    $args[] = '--dry-run';
}

$cmd = 'docker compose exec php php bin/console ' . implode(' ', array_map('escapeshellarg', $args));
passthru($cmd, $code);

if ($code === 0) {
    echo "  ✓ Migrations complete.\n";
}
exit($code);
