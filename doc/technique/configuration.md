# Configuration

> Voir aussi : [Adaptateurs](adaptateurs.md) · [Installation](../developpement/installation.md)

## Fichier `.env`

Le fichier `.env` à la racine du projet contient toutes les variables de configuration. Il est ignoré par git. Copiez `.env.example` pour commencer :

```bash
cp .env.example .env
```

## Variables disponibles

### Base de données

```ini
DATABASE_URL=postgresql://somanagent:secret@db:5432/somanagent?serverVersion=16&charset=utf8
```

| Paramètre | Description |
|---|---|
| `somanagent` (user) | Utilisateur PostgreSQL |
| `secret` | Mot de passe (à changer en production) |
| `db` | Hostname du conteneur Docker |
| `5432` | Port PostgreSQL |
| `somanagent` (db) | Nom de la base de données |

### Clé API Claude

```ini
CLAUDE_API_KEY=sk-ant-...
```

Requise pour le connecteur `claude_api`. Obtenez-la sur [console.anthropic.com](https://console.anthropic.com).

### Intégration GitHub

```ini
GITHUB_TOKEN=ghp_...
```

Personal Access Token GitHub. Droits requis : `repo`, `workflow`, `write:packages`.

### Intégration GitLab

```ini
GITLAB_TOKEN=glpat-...
GITLAB_URL=https://gitlab.com
```

`GITLAB_URL` peut être l'URL d'une instance GitLab self-hosted.

### Application Symfony

```ini
APP_ENV=dev
APP_SECRET=changethis
```

`APP_SECRET` doit être une chaîne aléatoire d'au moins 32 caractères.

## `.env.example`

```ini
# Base de données
DATABASE_URL=postgresql://somanagent:secret@db:5432/somanagent?serverVersion=16&charset=utf8

# Claude API
CLAUDE_API_KEY=

# GitHub
GITHUB_TOKEN=

# GitLab
GITLAB_TOKEN=
GITLAB_URL=https://gitlab.com

# Symfony
APP_ENV=dev
APP_SECRET=changethis
```

## Configuration Symfony

### `config/services.yaml`
Gère l'injection de dépendances. Points importants :
- `CLAUDE_API_KEY` est injecté dans `ClaudeApiAdapter`
- `skills_dir` pointe vers `../skills` (relatif au dossier backend)
- Les adapters IA sont taggués `app.agent_adapter` pour `AgentPortRegistry`

### `config/packages/doctrine.yaml`
- Mapping Doctrine sur `src/Entity/` (préfixe `App\Entity`)
- Type `uuid` mappé sur `Symfony\Bridge\Doctrine\Types\UuidType`
- `server_version: '16'` pour PostgreSQL 16

### `config/packages/nelmio_cors.yaml`
Autorise les requêtes cross-origin depuis le frontend (`localhost:5173`).

## Vérifier la configuration

```bash
php scripts/health.php
```

Vérifie que l'API répond et que les connecteurs configurés sont joignables.
