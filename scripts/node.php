#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Run reusable commands inside the Node Docker container
// Usage: php scripts/node.php type-check
// Usage: php scripts/node.php run build
// Usage: php scripts/node.php exec npm install
// Usage: php scripts/node.php shell

require_once __DIR__ . '/src/Application.php';
require_once __DIR__ . '/src/NodeCommandRunner.php';

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
    $c->line('Usage: php scripts/node.php type-check');
    $c->line('Usage: php scripts/node.php run build');
    $c->line('Usage: php scripts/node.php exec npm install');
    $c->line('Usage: php scripts/node.php shell');
    exit(1);
}

try {
    $runner = new NodeCommandRunner($app);
    exit($runner->run(array_slice($argv, 1)));
} catch (\InvalidArgumentException $e) {
    $c->fail($e->getMessage());
}
