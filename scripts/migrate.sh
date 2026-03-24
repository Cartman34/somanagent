#!/usr/bin/env bash
# scripts/migrate.sh — Exécute les migrations Doctrine dans le conteneur PHP
set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "▶ Migrations Doctrine..."
"$ROOT/scripts/console.sh" doctrine:migrations:migrate --no-interaction
echo "✓ Migrations terminées."
