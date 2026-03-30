#!/usr/bin/env php
<?php
// Description: Run a Symfony bin/console command inside the PHP Docker container
// Usage: php scripts/console.php <command> [args...]
// Usage: php scripts/console.php doctrine:migrations:migrate --no-interaction
// Usage: php scripts/console.php cache:clear

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

if ($argc < 2) {
    $c->line('Usage: php scripts/console.php <command> [args...]');
    $c->line('Ex:    php scripts/console.php doctrine:migrations:migrate --no-interaction');
    exit(1);
}

$escapedArgs = implode(' ', array_map('escapeshellarg', array_slice($argv, 1)));
$code        = $app->runCommand("docker compose exec -T php php bin/console $escapedArgs");
exit($code);
