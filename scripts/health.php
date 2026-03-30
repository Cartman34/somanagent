#!/usr/bin/env php
<?php
// Description: Check application and connector health via the API
// Usage: php scripts/health.php
// Usage: php scripts/health.php --url http://localhost:8080

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

$baseUrl = 'http://localhost:8080';
foreach (array_slice($argv, 1) as $i => $arg) {
    if ($arg === '--url' && isset($argv[$i + 2])) {
        $baseUrl = $argv[$i + 2];
    }
}

$get = static function (string $url): array {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new \RuntimeException("Cannot reach $url");
    }
    $data = json_decode($raw, true);
    if ($data === null) {
        throw new \RuntimeException("Non-JSON response from $url");
    }
    return $data;
};

$c->step("Checking SoManAgent ($baseUrl)");

try {
    $app = $get("$baseUrl/api/health");
    $c->ok("Application  : {$app['app']} v{$app['version']}");

    $connectors = $get("$baseUrl/api/health/connectors");

    $c->line();
    $c->line('  Connectors:');
    $allOk = true;
    foreach ($connectors['connectors'] as $name => $ok) {
        $icon   = $ok ? '✓' : '✗';
        $status = $ok ? 'OK' : 'UNREACHABLE';
        $c->line("    $icon  $name : $status");
        if (!$ok) {
            $allOk = false;
        }
    }

    $c->line();
    $c->line('  Overall status: ' . ($allOk ? '✓ OK' : '⚠  Degraded'));
    exit($allOk ? 0 : 1);
} catch (\RuntimeException $e) {
    $c->fail($e->getMessage() . "\n     → Run: php scripts/dev.php");
}
