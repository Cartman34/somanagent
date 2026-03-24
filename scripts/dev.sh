#!/usr/bin/env bash
# scripts/dev.sh — Démarre l'environnement de développement
set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "▶ Démarrage de SoManAgent..."
docker compose up -d

echo ""
echo "  API  →  http://localhost:8080/api/health"
echo "  UI   →  http://localhost:5173"
echo "  DB   →  localhost:5432 (somanagent / somanagent)"
echo ""
echo "Logs : scripts/logs.sh [php|node|db]"
