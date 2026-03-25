#!/usr/bin/env php
<?php
// Description: Stream logs from a Docker container in real time
// Usage: php scripts/logs.php [php|node|db|nginx]
// Usage: php scripts/logs.php php
// Usage: php scripts/logs.php db --tail 50

require_once __DIR__ . '/src/Bootstrap.php';

$root = dirname(__DIR__);
chdir($root);

$allowed   = ['php', 'node', 'db', 'nginx'];
$service   = 'php';
$extraArgs = '';

foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, $allowed, true)) {
        $service = $arg;
    } elseif (str_starts_with($arg, '--')) {
        $extraArgs .= ' ' . escapeshellarg($arg);
    }
}

passthru("docker compose logs -f $extraArgs " . escapeshellarg($service), $code);
exit($code);
