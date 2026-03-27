# Référence API REST

> Voir aussi : [Architecture](architecture.md) · [Entités](entities.md)

**Base URL** : `http://localhost:8080/api`
**Format** : JSON (`Content-Type: application/json`)
**Authentification** : aucune (accès local uniquement)

---

## Santé

### `GET /api/health`
```json
{ "status": "ok", "version": "1.0.0", "app": "SoManAgent" }
```

### `GET /api/health/connectors`
```json
{ "status": "ok", "connectors": { "claude_api": true, "claude_cli": false } }
```

---

## Projets

### `GET /api/projects`
```json
[{ "id": "...", "name": "MonSaaS", "description": null, "repositoryUrl": "https://github.com/…", "modules": 3, "createdAt": "…", "updatedAt": "…" }]
```

### `POST /api/projects`
**Body :** `{ "name": "MonSaaS", "description": "…", "repositoryUrl": "https://github.com/…" }`
**201 :** `{ "id": "…", "name": "…", "repositoryUrl": "…" }`

### `GET /api/projects/{id}` · `PUT /api/projects/{id}` · `DELETE /api/projects/{id}`
PUT accepte les mêmes champs que POST. DELETE cascade les modules et features.

### `POST /api/projects/{id}/modules`
```json
{ "name": "api-php", "repositoryUrl": "https://…", "stack": "PHP 8.4, Symfony 7" }
```

### `PUT /api/projects/modules/{id}` · `DELETE /api/projects/modules/{id}`

### `GET /api/projects/{id}/audit?page=1&limit=25`
Journal d'audit filtré sur le projet et ses tâches (entityType `Project` ou `Task`). Retourne `{ data, total, page, limit }`.

### `GET /api/projects/{id}/tokens`
Consommation de tokens pour ce projet. Retourne `{ summary: { total, byAgent }, entries }`.

---

## Rôles

### `GET /api/roles`
```json
[{ "id": "…", "slug": "dev-php", "name": "Développeur PHP", "description": "…", "skills": [{ "id": "…", "name": "…" }] }]
```

### `POST /api/roles`
**Body :** `{ "slug": "dev-php", "name": "Développeur PHP", "description": "…" }`

### `GET /api/roles/{id}` · `PUT /api/roles/{id}` · `DELETE /api/roles/{id}`

### `POST /api/roles/{id}/skills`
**Body :** `{ "skillId": "uuid" }`

### `DELETE /api/roles/{id}/skills/{skillId}`

---

## Équipes

### `GET /api/teams`
```json
[{ "id": "…", "name": "Équipe Web", "description": "…", "agentCount": 9, "createdAt": "…" }]
```

### `POST /api/teams`
**Body :** `{ "name": "Équipe Web", "description": "…" }`

### `GET /api/teams/{id}`
```json
{
  "id": "…",
  "name": "Équipe Web",
  "agents": [
    { "id": "…", "name": "PHP — David", "isActive": true, "role": { "id": "…", "name": "Développeur PHP", "slug": "dev-php" } }
  ]
}
```

### `PUT /api/teams/{id}` · `DELETE /api/teams/{id}`

### `POST /api/teams/{id}/agents`
**Body :** `{ "agentId": "uuid" }`  **204 No Content**

### `DELETE /api/teams/{id}/agents/{agentId}`
**204 No Content**

---

## Agents

### `GET /api/agents`
```json
[{
  "id": "…", "name": "PHP — David", "connector": "claude_api", "isActive": true,
  "role": { "id": "…", "name": "Développeur PHP", "slug": "dev-php" },
  "config": { "model": "claude-sonnet-4-6", "max_tokens": 4096, "temperature": 0.7, "timeout": 120 }
}]
```

### `POST /api/agents`
```json
{ "name": "PHP — David", "connector": "claude_api", "roleId": "uuid", "config": { "model": "claude-sonnet-4-6" } }
```

### `GET /api/agents/{id}` · `PUT /api/agents/{id}` · `DELETE /api/agents/{id}`

---

## Features

### `GET /api/projects/{projectId}/features`
```json
[{ "id": "…", "name": "Auth OAuth", "status": "open", "createdAt": "…" }]
```

### `POST /api/projects/{projectId}/features`
**Body :** `{ "name": "Auth OAuth", "description": "…" }`

### `GET /api/features/{id}` · `PUT /api/features/{id}` · `DELETE /api/features/{id}`

---

## Tâches

### `GET /api/projects/{projectId}/tasks`
Retourne les tâches racines (sans parent).

### `POST /api/projects/{projectId}/tasks`
```json
{
  "title": "Implémenter OAuth2",
  "type": "user_story",
  "priority": "high",
  "featureId": "uuid",
  "parentId": "uuid",
  "assignedAgentId": "uuid"
}
```

### `GET /api/tasks/{id}`
Retourne la tâche avec `children` (sous-tâches), `logs` et `tokenUsage` (consommation de tokens).

### `PUT /api/tasks/{id}`
Mise à jour des champs modifiables.

### `PATCH /api/tasks/{id}/status`
**Body :** `{ "status": "in_progress" }`

### `PATCH /api/tasks/{id}/progress`
**Body :** `{ "progress": 75 }`

### `PATCH /api/tasks/{id}/priority`
**Body :** `{ "priority": "critical" }`

### `POST /api/tasks/{id}/validate`
Passe le statut à `done` (100%). Validation manuelle par l'humain.

### `POST /api/tasks/{id}/reject`
**Body :** `{ "reason": "Critères non remplis." }` — repasse à `in_progress`.

### `POST /api/tasks/{id}/request-validation`
**Body :** `{ "comment": "Prêt pour revue." }` — passe à `review`.

### `DELETE /api/tasks/{id}`

---

## Chat

### `GET /api/projects/{projectId}/chat/{agentId}`
Retourne l'historique de conversation (200 derniers messages).

### `POST /api/projects/{projectId}/chat/{agentId}`
**Body :** `{ "content": "Peux-tu créer l'US pour le module auth ?" }`
**201 :** message créé.

---

## Tokens

### `GET /api/tokens/summary?from=2026-01-01&to=2026-12-31`
```json
{
  "total": { "input": 12500, "output": 8300, "calls": 47 },
  "byAgent": [{ "agentId": "…", "totalInput": "3200", "totalOutput": "2100", "calls": "12" }]
}
```

### `GET /api/tokens/agents/{agentId}?limit=100`
Détail des appels pour un agent.

---

## Compétences (Skills)

### `GET /api/skills` · `POST /api/skills` · `GET /api/skills/{id}`
### `PUT /api/skills/{id}/content` · `DELETE /api/skills/{id}`
### `POST /api/skills/import` — Body : `{ "source": "anthropics/code-reviewer" }`

---

## Workflows

### `GET /api/workflows` · `POST /api/workflows`
### `GET /api/workflows/{id}` · `PUT /api/workflows/{id}` · `DELETE /api/workflows/{id}`

---

## Audit

### `GET /api/audit`
Paramètres : `page`, `limit`.

---

## Codes d'erreur

| Code | Signification |
|---|---|
| 400 | Requête malformée |
| 404 | Ressource introuvable |
| 422 | Données invalides (champ obligatoire manquant) |
| 500 | Erreur serveur |

```json
{ "error": "Message décrivant l'erreur" }
```
