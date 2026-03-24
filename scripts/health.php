#!/usr/bin/env php
<?php
// Description: Vérifie l'état de l'application et de ses connecteurs via l'API
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
        return ['error' => 'Impossible de joindre ' . $url];
    }
    return json_decode($raw, true) ?? ['error' => 'Réponse non-JSON'];
}

echo "▶ Vérification de SoManAgent ($baseUrl)...\n\n";

// Health général
$app = get("$baseUrl/api/health");
if (isset($app['error'])) {
    echo "  ❌ Application inaccessible : {$app['error']}\n";
    echo "     → Lancez : php scripts/dev.php\n";
    exit(1);
}
echo "  ✓ Application  : {$app['app']} v{$app['version']}\n";

// Connecteurs
$connectors = get("$baseUrl/api/health/connectors");
if (!isset($connectors['connectors'])) {
    echo "  ⚠  Connecteurs : impossible de vérifier\n";
    exit(0);
}

echo "\n  Connecteurs :\n";
$allOk = true;
foreach ($connectors['connectors'] as $name => $ok) {
    $icon   = $ok ? '✓' : '✗';
    $status = $ok ? 'OK' : 'INJOIGNABLE';
    echo "    $icon $name : $status\n";
    if (!$ok) {
        $allOk = false;
    }
}

echo "\n  Statut global : " . ($allOk ? "✓ OK" : "⚠  Dégradé") . "\n";
exit($allOk ? 0 : 1);
