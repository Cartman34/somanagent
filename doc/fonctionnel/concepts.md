# Concepts clés

> Voir aussi : [Présentation](presentation.md) · [Équipes et rôles](equipes-et-roles.md) · [Skills](skills.md) · [Agents](agents.md) · [Workflows](workflows.md)

Ce glossaire définit avec précision tous les termes utilisés dans SoManAgent.

---

## Projet

Un **Projet** représente un produit logiciel global (ex: "MonSaaS", "AppMobile").

- Un projet a un nom, une description optionnelle
- Un projet contient un ou plusieurs **Modules**
- Un projet peut être associé à une ou plusieurs **Équipes**

---

## Module

Un **Module** est une composante logicielle indépendante d'un Projet.

Exemples pour le projet "MonSaaS" :
- `api-php` — l'API REST en PHP
- `app-android` — le client Android
- `app-ios` — le client iOS
- `backoffice` — le tableau de bord admin

Chaque Module :
- A son propre **dépôt Git** (URL configurée)
- A sa propre **stack technique** (ex: "PHP 8.4, Symfony 7.2")
- A un **statut** : `active` ou `archived`

---

## Équipe

Une **Équipe** est un groupe de **Rôles** auxquels des **Agents** peuvent être affectés.

Les équipes sont **génériques** : une équipe "Web Dev Team" peut être réutilisée sur plusieurs projets. Elle définit *qui* travaille et *comment*, indépendamment du projet.

---

## Rôle

Un **Rôle** définit une responsabilité au sein d'une Équipe.

Exemples : Tech Lead, Développeur Backend, Reviewer, QA, DevOps.

Un Rôle :
- Appartient à une Équipe
- A un **skill** associé (slug du SKILL.md à utiliser)
- Est la cible d'une étape de Workflow

---

## Agent

Un **Agent** est une instance d'IA configurée, prête à recevoir des tâches.

Un Agent :
- A un **connecteur** : `claude_api` (HTTP) ou `claude_cli` (binaire local)
- A une **configuration** : modèle, température, max_tokens, timeout
- Peut être assigné à un **Rôle**
- A un statut actif/inactif

Un Agent correspond à "une IA avec ses paramètres". Plusieurs agents peuvent utiliser le même connecteur avec des configurations différentes (ex: un agent "créatif" avec température élevée, un agent "précis" avec température basse).

---

## Skill

Un **Skill** est un fichier `SKILL.md` qui contient les **instructions** données à un agent pour un type de tâche.

Format (compatible [skills.sh](https://skills.sh)) :

```markdown
---
name: code-reviewer
description: Review code for quality, security and best practices
---

## Instructions
Quand tu analyses du code, tu dois...
```

Un Skill :
- A un **slug** unique (ex: `code-reviewer`)
- A une **source** : `imported` (depuis skills.sh) ou `custom` (créé localement)
- Est stocké sur disque dans `skills/imported/` ou `skills/custom/`
- Peut être **édité localement** même s'il a été importé

---

## Workflow

Un **Workflow** est une séquence d'**étapes** à exécuter par des agents.

Un Workflow :
- A un **déclencheur** : `manual`, `vcs_event` (PR/MR), ou `scheduled`
- Est associé à une **Équipe** (pour résoudre les rôles → agents)
- Contient des **étapes** ordonnées

### Étape de Workflow

Chaque étape définit :
- Le **rôle** qui l'exécute (ex: "Reviewer")
- Le **skill** à utiliser
- La **source de l'input** (diff VCS, output de l'étape précédente, manuel)
- L'**output_key** : nom de la variable de sortie réutilisable
- Une **condition** optionnelle (ex: n'exécuter que si l'étape précédente a trouvé des erreurs)

---

## Connecteur

Un **Connecteur** définit *comment* SoManAgent communique avec l'IA.

| Connecteur | Méthode | Cas d'usage |
|---|---|---|
| `claude_api` | HTTP vers api.anthropic.com | Utilisation serveur, sans interface |
| `claude_cli` | Binaire `claude` local | Claude Code installé sur la machine |

→ Voir [Adaptateurs](../technique/adaptateurs.md) pour les détails techniques.

---

## Audit Log

Chaque action importante dans SoManAgent (création, modification, exécution de workflow, import de skill…) génère une entrée dans le **journal d'audit**.

Le journal est consulable via l'interface web ou l'API (`GET /api/audit`).

---

## Résumé des relations

```
Projet
  └── Module (1..n)

Équipe
  └── Rôle (1..n)
        └── skillSlug → Skill

Agent
  └── Rôle (optionnel)
  └── ConnectorType → Adapter IA

Workflow
  └── Équipe
  └── WorkflowStep (1..n)
        ├── roleSlug  → Rôle → Agent
        └── skillSlug → Skill
```
