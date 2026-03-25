#!/usr/bin/env php
<?php
// Description: Start or stop the development environment (Docker Compose)
// Usage: php scripts/dev.php
// Usage: php scripts/dev.php --stop

require_once __DIR__ . '/src/Bootstrap.php';

$root = dirname(__DIR__);
chdir($root);

$stop = in_array('--stop', $argv, true);

if ($stop) {
    echo "▶ Stopping containers...\n";
    passthru('docker compose down', $code);
    echo $code === 0 ? "  ✓ Containers stopped.\n" : "  ❌ Error while stopping.\n";
    exit($code);
}

echo "▶ Starting SoManAgent...\n";
passthru('docker compose up -d', $code);

if ($code !== 0) {
    echo "  ❌ Could not start Docker Compose.\n";
    exit($code);
}

echo "\n";
echo "  API  →  http://localhost:8080/api/health\n";
echo "  UI   →  http://localhost:5173\n";
echo "  DB   →  localhost:5432  (somanagent / somanagent)\n";
echo "\n";
echo "  Logs  : php scripts/logs.php [php|node|db|nginx]\n";
echo "  Stop  : php scripts/dev.php --stop\n";
