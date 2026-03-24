# SoManAgent — Documentation

**SoManAgent** (Squad of Managed Agents) est une application web de gestion d'équipes d'agents IA pour le développement logiciel.

Elle permet de constituer des équipes génériques d'agents IA, de leur assigner des rôles et des skills, puis de les orchestrer via des workflows pour produire du code, effectuer des revues, générer des tests, etc.

---

## Navigation

### Documentation fonctionnelle — comprendre SoManAgent

| Document | Description |
|---|---|
| [Présentation](fonctionnel/presentation.md) | Qu'est-ce que SoManAgent, à quoi ça sert |
| [Concepts clés](fonctionnel/concepts.md) | Glossaire : Projet, Module, Équipe, Rôle, Agent, Skill, Workflow |
| [Équipes et rôles](fonctionnel/equipes-et-roles.md) | Créer et gérer des équipes, définir des rôles |
| [Skills](fonctionnel/skills.md) | Importer, créer et éditer des skills |
| [Agents IA](fonctionnel/agents.md) | Configurer les agents et leurs connecteurs |
| [Workflows](fonctionnel/workflows.md) | Définir et exécuter des workflows |

### Documentation technique — comprendre le code

| Document | Description |
|---|---|
| [Architecture](technique/architecture.md) | Structure du code, conventions, architecture hexagonale |
| [Entités](technique/entites.md) | Modèle de données, entités Doctrine et leurs relations |
| [API REST](technique/api.md) | Référence complète de tous les endpoints |
| [Adaptateurs](technique/adaptateurs.md) | Ports hexagonaux et leurs implémentations |
| [Configuration](technique/configuration.md) | Variables d'environnement, fichier .env |

### Documentation développement — travailler sur SoManAgent

| Document | Description |
|---|---|
| [Installation](developpement/installation.md) | Prérequis et mise en route complète |
| [Scripts](developpement/scripts.md) | Scripts disponibles dans `scripts/` |
| [Commandes Symfony](developpement/commandes.md) | Commandes `bin/console` disponibles |

---

## Démarrage rapide

```bash
# 1. Copier et configurer l'environnement
cp .env.example .env
# éditer .env : CLAUDE_API_KEY, GITHUB_TOKEN, etc.

# 2. Installation complète
php scripts/setup.php

# 3. Vérifier que tout fonctionne
php scripts/health.php
```

**API** : `http://localhost:8080/api/health`
**Interface** : `http://localhost:5173`

---

## Structure du projet

```
somanagent/
├── backend/          # API PHP (Symfony 7.2)
├── frontend/         # Interface web (React + TypeScript)
├── skills/           # Skills locaux (SKILL.md)
│   ├── imported/     # Importés depuis skills.sh
│   └── custom/       # Créés dans SoManAgent
├── scripts/          # Scripts de maintenance
└── doc/              # Cette documentation
```
