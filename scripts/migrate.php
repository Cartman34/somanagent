#!/usr/bin/env php
<?php
// Description: Exécute les migrations Doctrine dans le conteneur PHP
// Usage: php scripts/migrate.php
// Usage: php scripts/migrate.php --dry-run

$root = dirname(__DIR__);
chdir($root);

$dryRun = in_array('--dry-run', $argv, true);

echo "▶ Migrations Doctrine" . ($dryRun ? ' (dry-run)' : '') . "...\n";

$args = ['doctrine:migrations:migrate', '--no-interaction'];
if ($dryRun) {
    $args[] = '--dry-run';
}

$cmd = 'docker compose exec php php bin/console ' . implode(' ', array_map('escapeshellarg', $args));
passthru($cmd, $code);

if ($code === 0) {
    echo "  ✓ Migrations terminées.\n";
}
exit($code);
