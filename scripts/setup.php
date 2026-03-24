#!/usr/bin/env php
<?php
// Description: Installation complète de SoManAgent (première mise en route)
// Usage: php scripts/setup.php
// Usage: php scripts/setup.php --skip-frontend

$root = dirname(__DIR__);
chdir($root);

$skipFrontend = in_array('--skip-frontend', $argv, true);

function step(string $label): void {
    echo "\n▶ $label...\n";
}

function ok(string $msg): void {
    echo "  ✓ $msg\n";
}

function fail(string $msg, int $code = 1): never {
    echo "  ❌ $msg\n";
    exit($code);
}

function run(string $cmd, bool $failOnError = true): int {
    passthru($cmd, $code);
    if ($failOnError && $code !== 0) {
        fail("Commande échouée : $cmd", $code);
    }
    return $code;
}

echo str_repeat('═', 50) . "\n";
echo "     SoManAgent — Setup initial\n";
echo str_repeat('═', 50) . "\n";

// .env
step('Vérification du fichier .env');
if (!file_exists("$root/.env")) {
    copy("$root/.env.example", "$root/.env");
    ok('.env créé depuis .env.example — remplissez les valeurs avant de continuer.');
    echo "  → Éditez .env puis relancez ce script.\n";
    exit(0);
} else {
    ok('.env déjà présent');
}

// Docker
step('Démarrage des conteneurs Docker');
run('docker compose up -d --build');
ok('Conteneurs démarrés');

// Attente PostgreSQL
step('Attente de PostgreSQL');
$tries = 0;
do {
    sleep(1);
    $tries++;
    exec('docker compose exec -T db pg_isready -U somanagent -q 2>/dev/null', $out, $code);
} while ($code !== 0 && $tries < 30);

if ($code !== 0) {
    fail('PostgreSQL ne répond pas après 30 secondes.');
}
ok("PostgreSQL prêt ($tries s)");

// Migrations
step('Migrations Doctrine');
run('php scripts/console.php doctrine:migrations:migrate --no-interaction');
ok('Migrations exécutées');

// Frontend
if (!$skipFrontend) {
    step('Installation des dépendances frontend (npm)');
    run('docker compose exec -T node npm install');
    ok('npm install terminé');
} else {
    echo "  → Frontend ignoré (--skip-frontend)\n";
}

echo "\n" . str_repeat('═', 50) . "\n";
echo "  ✓ SoManAgent est prêt !\n\n";
echo "  API  →  http://localhost:8080/api/health\n";
echo "  UI   →  http://localhost:5173\n";
echo str_repeat('═', 50) . "\n\n";
