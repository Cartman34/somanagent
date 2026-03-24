# Skills

> Voir aussi : [Concepts clés](concepts.md) · [Agents](agents.md) · [Workflows](workflows.md)

## Qu'est-ce qu'un skill ?

Un skill est un fichier `SKILL.md` qui contient les instructions données à un agent IA pour accomplir un type de tâche précis. Il suit le format de [skills.sh](https://skills.sh) (ecosystème ouvert de Vercel).

### Format SKILL.md

```markdown
---
name: code-reviewer
description: Review code for quality, security and best practices
---

## Instructions

Tu es un expert en revue de code. Quand tu analyses du code soumis, tu dois :

1. Identifier les bugs potentiels
2. Vérifier les bonnes pratiques du langage
3. Signaler les problèmes de sécurité
4. Suggérer des améliorations de lisibilité

## Format de réponse

Retourne ta revue sous ce format :
- **Critique** : problèmes bloquants
- **Avertissement** : points à améliorer
- **Suggestion** : optimisations optionnelles
```

Le frontmatter YAML (entre `---`) contient les métadonnées. Le corps Markdown contient les instructions.

## Importer un skill depuis skills.sh

**Via l'interface** : Catalogue de skills → "Importer depuis skills.sh" → saisir `owner/skill-name`

**Via l'API** :
```http
POST /api/skills/import
Content-Type: application/json

{ "source": "anthropics/code-reviewer" }
```

**Via la commande** :
```bash
php scripts/console.php somanagent:skill:import anthropics/code-reviewer
```

Le skill est :
1. Téléchargé via `npx skills add` dans `skills/imported/`
2. Parsé (frontmatter + contenu)
3. Enregistré en base de données

## Créer un skill personnalisé

**Via l'interface** : Catalogue de skills → "Nouveau skill" → remplir le formulaire + éditeur Markdown intégré

**Via l'API** :
```http
POST /api/skills
Content-Type: application/json

{
  "slug": "mon-skill",
  "name": "Mon skill personnalisé",
  "description": "Description courte",
  "content": "---\nname: mon-skill\n...\n---\n\n## Instructions\n..."
}
```

Le fichier est créé dans `skills/custom/mon-skill/SKILL.md`.

## Modifier un skill

Tout skill (importé ou custom) peut être édité localement. Les modifications sont :
- Sauvegardées en base de données
- Écrites sur le fichier `SKILL.md` correspondant

```http
PUT /api/skills/{id}/content
Content-Type: application/json

{ "content": "---\nname: ...\n---\n\n## Instructions modifiées..." }
```

## Organisation sur disque

```
skills/
├── imported/
│   └── code-reviewer/
│       └── SKILL.md       ← importé depuis skills.sh
└── custom/
    └── mon-skill/
        └── SKILL.md       ← créé dans SoManAgent
```

## Associer un skill à un rôle

Le `skillSlug` d'un rôle détermine quel skill sera injecté dans le prompt quand ce rôle intervient dans un workflow.

```
Rôle "Reviewer" → skillSlug: "code-reviewer"
                       │
                       └── skills/imported/code-reviewer/SKILL.md
                                 │
                                 └── injecté dans Prompt.build()
```

→ Voir [Équipes et rôles](equipes-et-roles.md) pour associer un skill à un rôle.
→ Voir [Adaptateurs](../technique/adaptateurs.md) pour le mécanisme d'injection dans le prompt.
