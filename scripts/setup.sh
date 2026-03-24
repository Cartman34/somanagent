#!/usr/bin/env bash
# scripts/setup.sh — Installation complète (première fois)
set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "╔══════════════════════════════════════╗"
echo "║     SoManAgent — Setup initial       ║"
echo "╚══════════════════════════════════════╝"

# Copie du .env si absent
if [ ! -f .env ]; then
  cp .env.example .env
  echo "✓ .env créé depuis .env.example — pensez à le remplir !"
fi

# Build et démarrage Docker
echo ""
echo "▶ Démarrage des conteneurs Docker..."
docker compose up -d --build

# Attente que PostgreSQL soit prêt
echo "▶ Attente de PostgreSQL..."
until docker compose exec -T db pg_isready -U somanagent -q 2>/dev/null; do
  printf "."
  sleep 1
done
echo " OK"

# Migrations
echo "▶ Exécution des migrations..."
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction

# Seed équipe Web Dev (optionnel)
read -p "Voulez-vous créer l'équipe Web Development Team d'exemple ? [o/N] " confirm
if [[ "$confirm" =~ ^[Oo]$ ]]; then
  docker compose exec -T php php bin/console somanagent:seed:web-team
fi

# Frontend
echo "▶ Installation des dépendances frontend..."
docker compose exec -T node npm install

echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║  ✓ SoManAgent est prêt !                             ║"
echo "║                                                      ║"
echo "║  API  →  http://localhost:8080/api/health            ║"
echo "║  UI   →  http://localhost:5173                       ║"
echo "╚══════════════════════════════════════════════════════╝"
