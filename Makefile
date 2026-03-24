.PHONY: help install start stop restart build logs shell-php shell-node migrate db-reset test lint

# Couleurs
GREEN  := \033[0;32m
YELLOW := \033[0;33m
CYAN   := \033[0;36m
RESET  := \033[0m

help: ## Affiche cette aide
	@echo ""
	@echo "$(CYAN)SoManAgent — Commandes disponibles$(RESET)"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-20s$(RESET) %s\n", $$1, $$2}'
	@echo ""

# ============================================================
# DOCKER
# ============================================================

install: ## Installation complète (première fois)
	cp -n .env.example .env || true
	docker compose build --no-cache
	docker compose run --rm php composer install
	docker compose run --rm php php bin/console doctrine:migrations:migrate --no-interaction
	docker compose run --rm node npm install
	@echo "$(GREEN)Installation terminée. Lancez 'make start'$(RESET)"

start: ## Démarrer les conteneurs
	docker compose up -d
	@echo "$(GREEN)SoManAgent démarré$(RESET)"
	@echo "  API  : http://localhost:8080"
	@echo "  App  : http://localhost:5173"

stop: ## Arrêter les conteneurs
	docker compose down

restart: ## Redémarrer les conteneurs
	docker compose restart

build: ## Rebuild les images Docker
	docker compose build

logs: ## Afficher les logs en temps réel
	docker compose logs -f

logs-php: ## Logs PHP uniquement
	docker compose logs -f php

logs-node: ## Logs Node uniquement
	docker compose logs -f node

# ============================================================
# SHELLS
# ============================================================

shell-php: ## Shell dans le conteneur PHP
	docker compose exec php sh

shell-node: ## Shell dans le conteneur Node
	docker compose exec node sh

shell-db: ## Shell PostgreSQL
	docker compose exec db psql -U somanagent -d somanagent

# ============================================================
# BACKEND SYMFONY
# ============================================================

migrate: ## Lancer les migrations Doctrine
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

migration: ## Créer une nouvelle migration
	docker compose exec php php bin/console doctrine:migrations:diff

db-reset: ## Réinitialiser la base de données
	docker compose exec php php bin/console doctrine:schema:drop --force
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

cache-clear: ## Vider le cache Symfony
	docker compose exec php php bin/console cache:clear

test-backend: ## Lancer les tests PHP
	docker compose exec php php bin/phpunit

lint-php: ## Linter PHP (php-cs-fixer)
	docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff

# ============================================================
# FRONTEND REACT
# ============================================================

dev-frontend: ## Lancer le serveur de dev frontend
	docker compose up -d node

build-frontend: ## Builder le frontend pour la production
	docker compose exec node npm run build

test-frontend: ## Lancer les tests frontend
	docker compose exec node npm test

lint-frontend: ## Linter le frontend
	docker compose exec node npm run lint

# ============================================================
# SKILLS
# ============================================================

skills-import: ## Importer un skill (usage: make skills-import SKILL=owner/name)
	docker compose exec node npx skills add $(SKILL)

skills-list: ## Lister les skills installés
	docker compose exec node npx skills list
