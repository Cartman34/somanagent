#!/usr/bin/env bash
# scripts/console.sh — Lance une commande Symfony dans le conteneur PHP
# Usage : scripts/console.sh <commande> [args...]
# Ex    : scripts/console.sh doctrine:migrations:migrate --no-interaction
#         scripts/console.sh somanagent:seed:web-team

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [ $# -eq 0 ]; then
  echo "Usage: $0 <symfony-command> [args...]"
  exit 1
fi

docker compose exec php php bin/console "$@"
