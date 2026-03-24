#!/usr/bin/env bash
# scripts/logs.sh [php|node|db|nginx] — Affiche les logs d'un conteneur
SERVICE="${1:-php}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
docker compose logs -f "$SERVICE"
