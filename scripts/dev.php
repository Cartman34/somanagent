#!/usr/bin/env php
<?php
// Description: Start or stop the development environment (Docker Compose)
// Usage: php scripts/dev.php
// Usage: php scripts/dev.php --stop

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

$stop = in_array('--stop', $argv, true);

try {
    if ($stop) {
        $c->step('Stopping containers');
        $code = $app->runCommand('docker compose down');
        if ($code !== 0) {
            throw new \RuntimeException("docker compose down failed (exit $code).");
        }
        $c->ok('Containers stopped.');
        exit(0);
    }

    $c->step('Starting SoManAgent');
    $code = $app->runCommand('docker compose up -d');
    if ($code !== 0) {
        throw new \RuntimeException("docker compose up failed (exit $code).");
    }
} catch (\RuntimeException $e) {
    $c->fail($e->getMessage());
}

$c->line();
$c->line('  API  →  http://localhost:8080/api/health');
$c->line('  UI   →  http://localhost:5173');
$c->line('  DB   →  localhost:5432  (somanagent / somanagent)');
$c->line();
$c->line('  Logs  : php scripts/logs.php [php|worker|node|db|nginx]');
$c->line('  Stop  : php scripts/dev.php --stop');
$c->line();
