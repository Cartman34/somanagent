# Agents IA

> Voir aussi : [Concepts clés](concepts.md) · [Équipes et rôles](equipes-et-roles.md) · [Adaptateurs](../technique/adaptateurs.md)

## Qu'est-ce qu'un agent ?

Un agent est une **instance d'IA configurée** : il combine un connecteur (comment joindre l'IA), un modèle, et des paramètres de génération. Un agent peut être assigné à un rôle dans une équipe.

## Connecteurs disponibles

| Connecteur | Valeur | Description |
|---|---|---|
| Claude API | `claude_api` | Appels HTTP vers `api.anthropic.com` — nécessite `CLAUDE_API_KEY` |
| Claude CLI | `claude_cli` | Exécute le binaire `claude` installé localement — nécessite Claude Code |

## Créer un agent

**Via l'interface** : Agents → "Nouvel agent" → remplir le formulaire

**Via l'API** :
```http
POST /api/agents
Content-Type: application/json

{
  "name": "Claude Reviewer",
  "description": "Agent de revue de code",
  "connector": "claude_api",
  "config": {
    "model": "claude-opus-4-5",
    "max_tokens": 8192,
    "temperature": 0.3,
    "timeout": 120
  },
  "roleId": "uuid-du-role-reviewer"
}
```

## Configuration (`AgentConfig`)

| Paramètre | Type | Défaut | Description |
|---|---|---|---|
| `model` | string | — | Modèle IA (ex: `claude-sonnet-4-5`, `claude-opus-4-5`) |
| `max_tokens` | int | 8192 | Nombre maximum de tokens en réponse |
| `temperature` | float | 0.7 | Créativité (0 = déterministe, 1 = créatif) |
| `timeout` | int | 120 | Timeout en secondes |
| `extra` | object | {} | Paramètres supplémentaires spécifiques au connecteur |

### Recommandations par usage

| Usage | Température | Modèle suggéré |
|---|---|---|
| Revue de code | 0.2 – 0.4 | `claude-opus-4-5` |
| Génération de code | 0.5 – 0.7 | `claude-sonnet-4-5` |
| Documentation | 0.6 – 0.8 | `claude-sonnet-4-5` |
| Tests | 0.3 – 0.5 | `claude-sonnet-4-5` |

## Vérifier qu'un connecteur est joignable

```bash
php scripts/health.php
```

Ou via l'API :
```http
GET /api/health/connectors
```

Retourne :
```json
{
  "status": "ok",
  "connectors": {
    "claude_api": true,
    "claude_cli": false
  }
}
```

## Comment fonctionne l'appel à un agent

Lors de l'exécution d'une étape de workflow :

1. SoManAgent identifie l'agent assigné au rôle de l'étape
2. Récupère le contenu du SKILL.md associé
3. Construit un `Prompt` (skill + contexte + instruction de la tâche)
4. Envoie via le connecteur configuré (`ClaudeApiAdapter` ou `ClaudeCliAdapter`)
5. Reçoit un `AgentResponse` avec le contenu + métadonnées (tokens, durée)
6. Enregistre dans l'audit log

→ Voir [Adaptateurs](../technique/adaptateurs.md) pour les détails d'implémentation.
