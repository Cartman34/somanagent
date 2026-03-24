# Scripts disponibles

> Voir aussi : [Installation](installation.md) · [Commandes Symfony](commandes.md)

Les scripts se trouvent dans `scripts/`. Tous les scripts PHP respectent la convention suivante : un **entête commenté** juste après le shebang, avec les tags `Description:` et `Usage:`.

```bash
# Voir tous les scripts disponibles
php scripts/help.php

# Voir l'aide d'un script précis
php scripts/help.php migrate.php
```

## Vue d'ensemble

| Script | Type | Rôle |
|---|---|---|
| `check-php.sh` | Bash | Vérifier que PHP 8.4+ est installé |
| `help.php` | PHP | Afficher l'aide de tous les scripts |
| `setup.php` | PHP | Installation complète (première fois) |
| `dev.php` | PHP | Démarrer / arrêter l'environnement |
| `migrate.php` | PHP | Exécuter les migrations Doctrine |
| `console.php` | PHP | Lancer une commande Symfony |
| `logs.php` | PHP | Afficher les logs Docker |
| `health.php` | PHP | Vérifier l'état de l'application |

## Détail par script

### `check-php.sh`
Vérifie que PHP ≥ 8.4 est disponible dans le PATH. C'est le seul script Bash du projet — les autres sont PHP.

```bash
bash scripts/check-php.sh
# ✓ PHP 8.4.5 détecté
```

---

### `help.php`
Affiche la liste de tous les scripts avec description et exemples d'usage. Parse les entêtes de chaque fichier script automatiquement.

```bash
php scripts/help.php              # liste tous les scripts
php scripts/help.php migrate.php  # détail d'un script
```

---

### `setup.php`
Installation complète du projet. À lancer une seule fois après le clonage.

```bash
php scripts/setup.php
php scripts/setup.php --skip-frontend  # sans npm install
```

---

### `dev.php`
Démarre ou arrête l'environnement Docker.

```bash
php scripts/dev.php           # démarrer
php scripts/dev.php --stop    # arrêter
```

---

### `migrate.php`
Exécute les migrations Doctrine dans le conteneur PHP.

```bash
php scripts/migrate.php             # exécuter les migrations
php scripts/migrate.php --dry-run   # simuler sans appliquer
```

---

### `console.php`
Exécute n'importe quelle commande `bin/console` dans le conteneur PHP.

```bash
php scripts/console.php cache:clear
php scripts/console.php doctrine:migrations:status
php scripts/console.php somanagent:seed:web-team
```

---

### `logs.php`
Affiche les logs d'un conteneur Docker en temps réel (tail -f).

```bash
php scripts/logs.php          # logs du conteneur php (défaut)
php scripts/logs.php db       # logs PostgreSQL
php scripts/logs.php node     # logs Vite
php scripts/logs.php nginx    # logs Nginx
```

---

### `health.php`
Interroge l'API pour vérifier l'état de l'application et de ses connecteurs.

```bash
php scripts/health.php
php scripts/health.php --url http://mon-serveur:8080
```

## Convention d'entête pour les scripts

Chaque script doit commencer par ce bloc (après le shebang) :

**PHP :**
```php
#!/usr/bin/env php
<?php
// Description: Description courte en une ligne
// Usage: php scripts/nom-du-script.php [options]
// Usage: php scripts/nom-du-script.php --flag valeur
```

**Bash :**
```bash
#!/usr/bin/env bash
# Description: Description courte en une ligne
# Usage: bash scripts/nom-du-script.sh [options]
```

`help.php` parse automatiquement ces entêtes pour générer son affichage.
