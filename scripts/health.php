#!/usr/bin/env php
<?php
// Description: Check application and connector health via the API
// Usage: php scripts/health.php
// Usage: php scripts/health.php --url http://localhost:8080

$root = dirname(__DIR__);
chdir($root);

$baseUrl = 'http://localhost:8080';
foreach (array_slice($argv, 1) as $i => $arg) {
    if ($arg === '--url' && isset($argv[$i + 2])) {
        $baseUrl = $argv[$i + 2];
    }
}

function get(string $url): array {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['error' => 'Cannot reach ' . $url];
    }
    return json_decode($raw, true) ?? ['error' => 'Non-JSON response'];
}

echo "▶ Checking SoManAgent ($baseUrl)...\n\n";

// General health
$app = get("$baseUrl/api/health");
if (isset($app['error'])) {
    echo "  ❌ Application unreachable: {$app['error']}\n";
    echo "     → Run: php scripts/dev.php\n";
    exit(1);
}
echo "  ✓ Application  : {$app['app']} v{$app['version']}\n";

// Connectors
$connectors = get("$baseUrl/api/health/connectors");
if (!isset($connectors['connectors'])) {
    echo "  ⚠  Connectors: unable to check\n";
    exit(0);
}

echo "\n  Connectors:\n";
$allOk = true;
foreach ($connectors['connectors'] as $name => $ok) {
    $icon   = $ok ? '✓' : '✗';
    $status = $ok ? 'OK' : 'UNREACHABLE';
    echo "    $icon $name : $status\n";
    if (!$ok) {
        $allOk = false;
    }
}

echo "\n  Overall status: " . ($allOk ? "✓ OK" : "⚠  Degraded") . "\n";
exit($allOk ? 0 : 1);
