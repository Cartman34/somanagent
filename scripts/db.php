#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run database-related commands inside Docker containers (PostgreSQL + PHP)
// Usage: php scripts/db.php query "SELECT 1"
// Usage: php scripts/db.php exec -c "\\dt"
// Usage: php scripts/db.php shell
// Usage: php scripts/db.php reset
// Usage: php scripts/db.php reset --fixtures
// Usage: php scripts/db.php reset --fixtures --force

if (in_array('-h', $argv, true) || in_array('--help', $argv, true)) {
    echo "Run database-related commands inside Docker containers (PostgreSQL + PHP)\n\n";
    echo "Usage: php scripts/db.php query \"SELECT 1\"\n";
    echo "Usage: php scripts/db.php exec -c \"\\\\dt\"\n";
    echo "Usage: php scripts/db.php shell\n";
    echo "Usage: php scripts/db.php reset\n";
    echo "Usage: php scripts/db.php reset --fixtures\n";
    echo "Usage: php scripts/db.php reset --fixtures --force\n";
    exit(0);
}

require_once __DIR__ . '/src/Application.php';
require_once __DIR__ . '/src/DoctrineRunner.php';

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
    $c->line('Usage: php scripts/db.php reset [--fixtures [--force]]');
    exit(1);
}

try {
    $runner = new DoctrineRunner($app);
    exit($runner->run(array_slice($argv, 1)));
} catch (\InvalidArgumentException $e) {
    $c->fail($e->getMessage());
}
