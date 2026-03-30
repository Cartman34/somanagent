#!/usr/bin/env php
<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */
// Description: Manage Claude CLI auth with WSL as the source of truth and sync it to Docker
// Usage: php scripts/claude-auth.php status
// Usage: php scripts/claude-auth.php sync [--force]
// Usage: php scripts/claude-auth.php login [--force]

declare(strict_types=1);

require_once __DIR__ . '/src/Application.php';
require_once __DIR__ . '/src/ClaudeAuthManager.php';

try {
    $app = new Application();
    $app->boot();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "\n❌ " . $e->getMessage() . "\n\n");
    exit(1);
}

$root = dirname(__DIR__);
chdir($root);

$command = $argv[1] ?? 'status';
$force = in_array('--force', $argv, true);

try {
    $manager = new ClaudeAuthManager($app, $root);

    match ($command) {
        'status' => $manager->showStatus(),
        'sync' => $manager->sync($force),
        'login' => $manager->loginAndSync($force),
        default => throw new \RuntimeException(sprintf('Unknown command "%s". Use status, sync, or login.', $command)),
    };
} catch (\RuntimeException $e) {
    $app->console->fail($e->getMessage());
}
