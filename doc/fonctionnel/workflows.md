# Workflows

> Voir aussi : [Concepts clés](concepts.md) · [Équipes et rôles](equipes-et-roles.md) · [Agents](agents.md) · [Skills](skills.md)

## Qu'est-ce qu'un workflow ?

Un workflow est une **séquence d'étapes** exécutées par des agents dans un ordre défini. Chaque étape confie une tâche à un agent (identifié par son rôle) en lui fournissant le bon skill et le bon contexte.

## Déclencheurs

| Valeur | Description |
|---|---|
| `manual` | Lancé manuellement depuis l'interface ou l'API |
| `vcs_event` | Déclenché par un événement Git (PR, MR ouverte) |
| `scheduled` | Planifié (futur) |

## Anatomie d'un workflow

```
Workflow "Revue de code"
├── trigger: manual
├── team: Web Development Team
└── Steps:
    ├── [1] Analyser le diff
    │       role: reviewer
    │       skill: code-reviewer
    │       input: diff VCS de la PR
    │       output_key: review_report
    │
    ├── [2] Corriger les problèmes
    │       role: backend-dev
    │       skill: backend-dev
    │       input: review_report (step précédente)
    │       condition: "review_report contient des erreurs critiques"
    │       output_key: fixed_code
    │
    └── [3] Valider les corrections
            role: reviewer
            skill: code-reviewer
            input: fixed_code
            output_key: validation_report
```

## Créer un workflow

**Via l'interface** : Workflows → "Nouveau workflow" → ajouter les étapes

**Via l'API** :
```http
POST /api/workflows
Content-Type: application/json

{
  "name": "Revue de code",
  "description": "Analyse une PR et propose des corrections",
  "trigger": "manual",
  "teamId": "uuid-de-l-equipe"
}
```

Puis ajouter les étapes (à définir).

## Configuration d'une étape

| Champ | Type | Description |
|---|---|---|
| `stepOrder` | int | Position dans la séquence (1, 2, 3…) |
| `name` | string | Libellé de l'étape |
| `roleSlug` | string | Slug du rôle qui exécute l'étape |
| `skillSlug` | string | Skill à injecter dans le prompt |
| `inputConfig` | object | Source et format de l'input |
| `outputKey` | string | Nom de la variable de sortie |
| `condition` | string | Condition d'exécution (null = toujours) |

### Sources d'input (`inputConfig`)

```json
{ "source": "vcs", "type": "pr_diff" }          // Diff d'une PR/MR
{ "source": "previous_step", "key": "review_report" }  // Output d'une étape précédente
{ "source": "manual", "prompt": "Votre texte..." }      // Texte saisi manuellement
```

## Statuts d'une étape

| Statut | Description |
|---|---|
| `pending` | En attente d'exécution |
| `running` | En cours d'exécution |
| `done` | Terminée avec succès |
| `error` | Erreur lors de l'exécution |
| `skipped` | Ignorée (condition non remplie) |

## Mode dry-run

Le mode dry-run permet de **simuler un workflow** sans envoyer de requêtes aux agents IA. Utile pour valider la configuration d'un workflow avant de l'exécuter réellement.

```http
POST /api/workflows/{id}/run
Content-Type: application/json

{ "dryRun": true }
```

En dry-run :
- Les étapes passent en statut `done` avec un output fictif
- Aucun appel API vers Claude n'est effectué
- L'audit log indique `workflow.dry_run`

## Journal d'audit

Chaque exécution de workflow génère des entrées dans le journal :
- `workflow.run` — démarrage
- `workflow.step.completed` — étape terminée
- `workflow.step.failed` — étape en erreur
- `workflow.completed` / `workflow.failed` — fin

→ Consultable via `GET /api/audit` ou dans l'interface.
