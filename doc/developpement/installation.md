# Installation et mise en route

> Voir aussi : [Configuration](../technique/configuration.md) · [Scripts](scripts.md) · [Commandes Symfony](commandes.md)

## Prérequis

| Outil | Version minimale | Vérification |
|---|---|---|
| PHP | 8.4+ | `php --version` |
| Docker Desktop | 24+ | `docker --version` |
| Docker Compose | v2+ | `docker compose version` |
| Git | — | `git --version` |

**Node.js n'est pas requis localement** — il tourne dans un conteneur Docker.

Vérifiez PHP avec le script dédié :
```bash
bash scripts/check-php.sh
```

## Installation complète (première fois)

```bash
# 1. Cloner le projet
git clone https://github.com/Cartman34/somanagent.git
cd somanagent

# 2. Configurer l'environnement
cp .env.example .env
# Éditez .env et renseignez au minimum CLAUDE_API_KEY

# 3. Lancer le setup automatique
php scripts/setup.php
```

Le script `setup.php` :
1. Vérifie que `.env` est présent
2. Démarre les conteneurs Docker (`docker compose up -d --build`)
3. Attend que PostgreSQL soit prêt
4. Exécute les migrations Doctrine
5. Lance `npm install` dans le conteneur Node

## Démarrer après installation

```bash
php scripts/dev.php
```

URLs :
- **API** : `http://localhost:8080/api/health`
- **Interface** : `http://localhost:5173`
- **DB** : `localhost:5432` (user: `somanagent`, pass: `somanagent`)

## Arrêter l'environnement

```bash
php scripts/dev.php --stop
```

## Structure Docker

Le `docker-compose.yml` définit 4 services :

| Service | Image | Port exposé | Rôle |
|---|---|---|---|
| `php` | PHP 8.4-FPM + Composer | — | Exécute Symfony |
| `nginx` | Nginx alpine | 8080 | Proxy vers PHP-FPM |
| `db` | PostgreSQL 16 | 5432 | Base de données |
| `node` | Node 20 alpine | 5173 | Dev server Vite |

## Migrations

Les migrations sont dans `backend/migrations/`. Pour les appliquer :

```bash
php scripts/migrate.php
```

Pour vérifier le statut :
```bash
php scripts/console.php doctrine:migrations:status
```

## Données d'exemple

Pour créer l'équipe Web Development Team d'exemple :

```bash
php scripts/console.php somanagent:seed:web-team
```

## Dépannage

### Docker ne démarre pas
```bash
# Vérifier l'état des conteneurs
docker compose ps
# Voir les logs
php scripts/logs.php php
php scripts/logs.php db
```

### Erreur de connexion à la base de données
- Vérifiez que `DATABASE_URL` dans `.env` correspond au service `db` de Docker
- Attendez quelques secondes que PostgreSQL finisse de démarrer

### Les migrations échouent
```bash
# Voir la liste des migrations et leur état
php scripts/console.php doctrine:migrations:status
# Voir les migrations disponibles
php scripts/console.php doctrine:migrations:list
```

### L'API répond mais les connecteurs Claude sont KO
- Vérifiez `CLAUDE_API_KEY` dans `.env`
- Pour `claude_cli` : vérifiez que le binaire `claude` est accessible dans le conteneur PHP
