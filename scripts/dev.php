#!/usr/bin/env php
<?php
// Description: Démarre l'environnement de développement (Docker Compose)
// Usage: php scripts/dev.php
// Usage: php scripts/dev.php --stop

$root = dirname(__DIR__);
chdir($root);

$stop = in_array('--stop', $argv, true);

if ($stop) {
    echo "▶ Arrêt des conteneurs...\n";
    passthru('docker compose down', $code);
    echo $code === 0 ? "  ✓ Conteneurs arrêtés.\n" : "  ❌ Erreur lors de l'arrêt.\n";
    exit($code);
}

echo "▶ Démarrage de SoManAgent...\n";
passthru('docker compose up -d', $code);

if ($code !== 0) {
    echo "  ❌ Impossible de démarrer Docker Compose.\n";
    exit($code);
}

echo "\n";
echo "  API  →  http://localhost:8080/api/health\n";
echo "  UI   →  http://localhost:5173\n";
echo "  DB   →  localhost:5432  (somanagent / somanagent)\n";
echo "\n";
echo "  Logs     : php scripts/logs.php [php|node|db|nginx]\n";
echo "  Arrêter  : php scripts/dev.php --stop\n";
