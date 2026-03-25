#!/usr/bin/env php
<?php
// Description: Run a Symfony bin/console command inside the PHP Docker container
// Usage: php scripts/console.php <command> [args...]
// Usage: php scripts/console.php doctrine:migrations:migrate --no-interaction
// Usage: php scripts/console.php cache:clear

require_once __DIR__ . '/_bootstrap.php';

$root = dirname(__DIR__);
chdir($root);

if ($argc < 2) {
    echo "Usage: php scripts/console.php <command> [args...]\n";
    echo "Ex:    php scripts/console.php doctrine:migrations:migrate --no-interaction\n";
    exit(1);
}

$args = array_slice($argv, 1);
$cmd  = 'docker compose exec php php bin/console ' . implode(' ', array_map('escapeshellarg', $args));

passthru($cmd, $code);
exit($code);
