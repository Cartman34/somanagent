# Équipes et Rôles

> Voir aussi : [Concepts clés](concepts.md) · [Agents](agents.md) · [Skills](skills.md) · [Workflows](workflows.md)

## Créer une équipe

Une équipe se crée depuis l'interface web (`/équipes → Nouvelle équipe`) ou via l'API :

```http
POST /api/teams
Content-Type: application/json

{
  "name": "Web Development Team",
  "description": "Équipe de développement web full-stack"
}
```

Les équipes sont **génériques** : elles ne sont pas liées à un projet spécifique et peuvent être réutilisées.

## Gérer les rôles d'une équipe

Depuis la page de détail d'une équipe, vous pouvez ajouter, modifier et supprimer des rôles directement via l'interface.

```http
POST /api/teams/{id}/roles
Content-Type: application/json

{
  "name": "Reviewer",
  "description": "Revue de code et contrôle qualité",
  "skillSlug": "code-reviewer"
}
```

Le champ `skillSlug` est optionnel mais recommandé : il définit quel skill sera utilisé quand ce rôle intervient dans un workflow.

## Équipe Web Development Team (exemple)

SoManAgent inclut une commande pour créer l'équipe d'exemple :

```bash
php scripts/console.php somanagent:seed:web-team
```

Elle crée l'équipe avec 6 rôles :

| Rôle | Skill associé | Responsabilité |
|---|---|---|
| Tech Lead | `architect` | Architecture, décisions techniques |
| Développeur Backend | `backend-dev` | Code serveur, API |
| Développeur Frontend | `frontend-dev` | UI, intégration |
| Reviewer | `code-reviewer` | Revue de code, qualité |
| QA | `qa-tester` | Tests, validation |
| DevOps | `devops` | CI/CD, infrastructure |

## Assigner un agent à un rôle

Un agent peut être assigné à un rôle depuis sa page de configuration. Lorsqu'un workflow a besoin du rôle "Reviewer", SoManAgent cherche l'agent actif assigné à ce rôle dans l'équipe concernée.

→ Voir [Agents](agents.md) pour configurer un agent.

## Exporter / Importer une équipe

> Fonctionnalité prévue dans une version future. Les équipes sont actuellement gérées exclusivement via l'interface.

L'export YAML d'une équipe aura le format :

```yaml
name: "Web Development Team"
description: "..."
roles:
  - name: "Reviewer"
    description: "Revue de code"
    skillSlug: "code-reviewer"
```
