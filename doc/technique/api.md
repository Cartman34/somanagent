# Référence API REST

> Voir aussi : [Architecture](architecture.md) · [Entités](entites.md)

**Base URL** : `http://localhost:8080/api`
**Format** : JSON (`Content-Type: application/json`)
**Authentification** : aucune (accès local uniquement)

---

## Santé

### `GET /api/health`
Vérifie que l'application est démarrée.

**Réponse 200 :**
```json
{ "status": "ok", "version": "1.0.0", "app": "SoManAgent" }
```

### `GET /api/health/connectors`
Vérifie l'accessibilité de chaque connecteur IA configuré.

**Réponse 200 (tout OK) :**
```json
{ "status": "ok", "connectors": { "claude_api": true, "claude_cli": false } }
```
**Réponse 207 (dégradé) :** même format, `status: "degraded"`.

---

## Projets

### `GET /api/projects`
Liste tous les projets.

```json
[
  {
    "id": "019...",
    "name": "MonSaaS",
    "description": null,
    "modules": 3,
    "createdAt": "2026-03-24T10:00:00+00:00",
    "updatedAt": "2026-03-24T10:00:00+00:00"
  }
]
```

### `POST /api/projects`
Crée un projet.

**Corps :** `{ "name": "MonSaaS", "description": "..." }`
**Réponse 201 :** `{ "id": "...", "name": "..." }`
**Erreur 422 :** si `name` absent.

### `GET /api/projects/{id}`
Détail d'un projet avec ses modules.

```json
{
  "id": "...",
  "name": "MonSaaS",
  "description": null,
  "modules": [
    {
      "id": "...",
      "name": "api-php",
      "description": null,
      "repositoryUrl": "https://github.com/user/api-php",
      "stack": "PHP 8.4, Symfony 7.2",
      "status": "active"
    }
  ],
  "createdAt": "...",
  "updatedAt": "..."
}
```

### `PUT /api/projects/{id}`
Met à jour un projet. Corps : `{ "name": "...", "description": "..." }`

### `DELETE /api/projects/{id}`
Supprime un projet (cascade sur ses modules). **204 No Content.**

---

## Modules (sous-ressource de Projet)

### `POST /api/projects/{id}/modules`
Ajoute un module à un projet.

**Corps :**
```json
{
  "name": "api-php",
  "description": "API REST",
  "repositoryUrl": "https://github.com/user/api-php",
  "stack": "PHP 8.4, Symfony 7.2"
}
```
**Réponse 201 :** `{ "id": "...", "name": "...", "repositoryUrl": "...", "status": "active" }`

### `PUT /api/projects/modules/{id}`
Met à jour un module. Mêmes champs que la création.

### `DELETE /api/projects/modules/{id}`
Supprime un module. **204 No Content.**

---

## Équipes

### `GET /api/teams`
Liste toutes les équipes (avec compteur de rôles).

### `POST /api/teams`
Crée une équipe. Corps : `{ "name": "...", "description": "..." }`

### `GET /api/teams/{id}`
Détail avec liste des rôles :
```json
{
  "id": "...",
  "name": "Web Dev Team",
  "roles": [
    { "id": "...", "name": "Reviewer", "description": "...", "skillSlug": "code-reviewer" }
  ]
}
```

### `PUT /api/teams/{id}` / `DELETE /api/teams/{id}`
Mise à jour / suppression.

### `POST /api/teams/{id}/roles`
Ajoute un rôle. Corps : `{ "name": "Reviewer", "description": "...", "skillSlug": "code-reviewer" }`

### `PUT /api/teams/roles/{id}` / `DELETE /api/teams/roles/{id}`
Mise à jour / suppression d'un rôle.

---

## Agents

### `GET /api/agents`
Liste tous les agents.

```json
[{
  "id": "...",
  "name": "Claude Reviewer",
  "connector": "claude_api",
  "isActive": true,
  "role": { "id": "...", "name": "Reviewer" },
  "config": { "model": "claude-opus-4-5", "max_tokens": 8192, "temperature": 0.3, "timeout": 120 }
}]
```

### `POST /api/agents`
Crée un agent.

```json
{
  "name": "Claude Reviewer",
  "connector": "claude_api",
  "config": { "model": "claude-opus-4-5", "temperature": 0.3 },
  "roleId": "uuid-du-role"
}
```

### `GET /api/agents/{id}` · `PUT /api/agents/{id}` · `DELETE /api/agents/{id}`
CRUD standard.

---

## Skills

### `GET /api/skills`
Liste tous les skills (sans contenu pour alléger).

```json
[{
  "id": "...",
  "slug": "code-reviewer",
  "name": "Code Reviewer",
  "source": "imported",
  "sourceLabel": "Importé (skills.sh)",
  "updatedAt": "..."
}]
```

### `GET /api/skills/{id}`
Détail avec contenu complet du SKILL.md.

### `POST /api/skills/import`
Importe un skill depuis skills.sh.
Corps : `{ "source": "anthropics/code-reviewer" }`

### `POST /api/skills`
Crée un skill custom.
Corps : `{ "slug": "mon-skill", "name": "...", "content": "---\n...", "description": "..." }`

### `PUT /api/skills/{id}/content`
Met à jour le contenu SKILL.md.
Corps : `{ "content": "---\nname: ...\n---\n\n..." }`

### `DELETE /api/skills/{id}`
Supprime un skill. **204 No Content.**

---

## Codes d'erreur

| Code | Signification |
|---|---|
| 400 | Requête malformée (JSON invalide) |
| 404 | Ressource introuvable |
| 422 | Données invalides (champ obligatoire manquant) |
| 500 | Erreur serveur |

Format d'erreur :
```json
{ "error": "Message décrivant l'erreur" }
```
