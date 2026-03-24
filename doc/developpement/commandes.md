# Commandes Symfony

> Voir aussi : [Scripts](scripts.md) · [Installation](installation.md)

Les commandes Symfony s'exécutent via `bin/console`. Dans le contexte Docker, utilisez :

```bash
php scripts/console.php <commande> [args...]
```

## Commandes SoManAgent

Ces commandes sont propres à SoManAgent (préfixe `somanagent:`).

### `somanagent:health`
Vérifie l'état des connecteurs IA (Claude API, Claude CLI).

```bash
php scripts/console.php somanagent:health
```

Sortie :
```
SoManAgent — Vérification des connecteurs
 ✓ claude_api
 ✗ claude_cli
 [WARNING] Certains connecteurs sont inaccessibles.
```

---

### `somanagent:skill:import`
Importe un skill depuis le registry skills.sh et l'enregistre en base.

```bash
php scripts/console.php somanagent:skill:import anthropics/code-reviewer
php scripts/console.php somanagent:skill:import vercel-labs/agent-skills
```

Equivalent à `POST /api/skills/import` mais utilisable en ligne de commande.

---

### `somanagent:seed:web-team`
Crée l'équipe "Web Development Team" d'exemple avec ses 6 rôles.

```bash
php scripts/console.php somanagent:seed:web-team
```

Roles créés : Tech Lead, Développeur Backend, Développeur Frontend, Reviewer, QA, DevOps.

---

## Commandes Doctrine (migrations)

### Statut des migrations
```bash
php scripts/console.php doctrine:migrations:status
php scripts/console.php doctrine:migrations:list
```

### Exécuter les migrations
```bash
php scripts/console.php doctrine:migrations:migrate --no-interaction
# ou via le script dédié :
php scripts/migrate.php
```

### Créer une nouvelle migration
```bash
php scripts/console.php doctrine:migrations:diff
```
Génère automatiquement une migration à partir du diff entre les entités et la base.

### Rollback
```bash
php scripts/console.php doctrine:migrations:execute --down 'DoctrineMigrations\Version20260324000001'
```

---

## Commandes Symfony utiles

### Cache
```bash
php scripts/console.php cache:clear
php scripts/console.php cache:warmup
```

### Debug
```bash
php scripts/console.php debug:router          # liste toutes les routes
php scripts/console.php debug:container       # liste les services
php scripts/console.php debug:config doctrine # config Doctrine effective
```

### Validation du schéma
```bash
php scripts/console.php doctrine:schema:validate   # vérifie la cohérence entités ↔ BDD
php scripts/console.php doctrine:schema:create --dump-sql  # SQL du schéma actuel
```
