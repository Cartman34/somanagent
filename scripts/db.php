#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run reusable commands inside the PostgreSQL Docker container
// Usage: php scripts/db.php query "SELECT 1"
// Usage: php scripts/db.php exec -c "\\dt"
// Usage: php scripts/db.php shell

require_once __DIR__ . '/src/Application.php';
require_once __DIR__ . '/src/PostgresCommandRunner.php';

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
    $c->line('Usage: php scripts/db.php query "SELECT 1"');
    $c->line('Usage: php scripts/db.php exec -c "\\dt"');
    $c->line('Usage: php scripts/db.php shell');
    exit(1);
}

try {
    $runner = new PostgresCommandRunner($app);
    exit($runner->run(array_slice($argv, 1)));
} catch (\InvalidArgumentException $e) {
    $c->fail($e->getMessage());
}
