#!/usr/bin/env bash
# scripts/seed.sh — Insère les données d'exemple (Web Development Team)
set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "▶ Seed Web Development Team..."
"$ROOT/scripts/console.sh" somanagent:seed:web-team
echo "✓ Seed terminé."
