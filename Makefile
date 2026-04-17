.PHONY: help install start stop restart build logs shell-php shell-node migrate db-reset db-reset-fixtures test lint

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
	cp -n .env.dist .env || true
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
	php scripts/node.php shell

shell-db: ## Shell PostgreSQL
	php scripts/db.php shell

# ============================================================
# BACKEND SYMFONY
# ============================================================

migrate: ## Lancer les migrations Doctrine
	php scripts/migrate.php

migration: ## Créer une nouvelle migration
	php scripts/console.php doctrine:migrations:diff

db-reset: ## Réinitialiser la base de données
	php scripts/db.php reset

db-reset-fixtures: ## Recréer la base locale et recharger les fixtures
	php scripts/db.php reset --fixtures

cache-clear: ## Vider le cache Symfony
	php scripts/console.php cache:clear

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
	php scripts/node.php build

test-frontend: ## Lancer les tests frontend
	php scripts/node.php test

lint-frontend: ## Linter le frontend
	php scripts/node.php lint

# ============================================================
# SKILLS
# ============================================================

skills-import: ## Importer un skill (usage: make skills-import SKILL=owner/name)
	docker compose exec node npx skills add $(SKILL)

skills-list: ## Lister les skills installés
	docker compose exec node npx skills list
