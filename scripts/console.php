#!/usr/bin/env php
<?php
// Description: Exécute une commande Symfony bin/console dans le conteneur PHP Docker
// Usage: php scripts/console.php <commande> [args...]
// Usage: php scripts/console.php doctrine:migrations:migrate --no-interaction
// Usage: php scripts/console.php cache:clear

$root = dirname(__DIR__);
chdir($root);

if ($argc < 2) {
    echo "Usage: php scripts/console.php <commande> [args...]\n";
    echo "Ex:    php scripts/console.php doctrine:migrations:migrate --no-interaction\n";
    exit(1);
}

$args = array_slice($argv, 1);
$cmd  = 'docker compose exec php php bin/console ' . implode(' ', array_map('escapeshellarg', $args));

passthru($cmd, $code);
exit($code);
