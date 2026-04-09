# Référence API REST

> Voir aussi : [Architecture](architecture.md) · [Entités](entities.md)

## OpenAPI Contract

The versioned HTTP contract for the API is maintained in [`openapi.yaml`](openapi.yaml).

The spec may document a route ahead of its implementation, but any operation that is not yet delivered must be explicitly marked with `x-somanagent-implemented: false`.

Maintenance rules for this file:
- update `doc/technical/openapi.yaml` in the same change as any API route, request, response, parameter, or payload format change
- keep the file hand-written and readable; prefer explicit schemas over generated output
- specify `required`, value formats, enums, and examples whenever they are needed to build usable example requests
- use `additionalProperties: false` for structured objects unless a free-form map is intentional
- if a route is planned but not implemented yet, keep it in the spec only with `x-somanagent-implemented: false`
- keep narrative business rules in `api.md`, but keep route-level contract details in `openapi.yaml`
- run `php scripts/validate-files.php ...` or `php scripts/review.php` after editing the spec so OpenAPI consistency stays enforced

**Base URL** : `http://localhost:8080/api`
**Format** : JSON
**Authentification** : aucune en local

## Santé

### `GET /api/health`
Retourne l’état global de l’application.

### `GET /api/health/connectors`
Retourne l’état détaillé des connecteurs avec checks normalisés.

Chaque connecteur expose notamment :
- `ok`
- `reason`
- `fixCommand`
- `authMethod`
- `checks.runtime`
- `checks.auth`
- `checks.prompt_test`
- `checks.models`

### `GET /api/health/claude-cli-auth`
Retourne l’état d’authentification Claude CLI.

### `GET /api/health/connectors/{connector}/auth`
Retourne le sous-état d’authentification normalisé pour un connecteur donné.

### `POST /api/health/connectors/{connector}/test`
Envoie un test réel `Say OK` via le connecteur ciblé, avec `message` et `model` optionnels.

## Projets

### `GET /api/projects`
Liste les projets.

Chaque projet expose aussi `dispatchMode` :
- `auto` : toute tâche éligible est dispatchée immédiatement
- `manual` : la tâche passe en `awaiting_dispatch` puis attend une autorisation explicite

### `POST /api/projects`
Crée un projet.

Le body peut inclure `teamId` pour affecter une équipe dès la création.

### `GET /api/projects/{id}` · `PUT /api/projects/{id}` · `DELETE /api/projects/{id}`
CRUD projet.

`PUT /api/projects/{id}` permet aussi :
- d’affecter ou retirer l’équipe via `teamId`
- de modifier `dispatchMode`

### `GET /api/projects/{id}/audit`
Journal d’audit du projet.

### `GET /api/projects/{id}/tokens`
Consommation de tokens du projet.

### `POST /api/projects/{id}/requests`
Crée une demande métier et la transforme en `Ticket`.

Précondition :
- le projet doit avoir une équipe affectée, sinon l’API renvoie une erreur fonctionnelle

Body :
```json
{
  "title": "Permettre l'export PDF",
  "description": "Le client veut exporter les rapports mensuels.",
  "priority": "high"
}
```

Réponse :
- ticket créé
- `dispatchError` éventuel si le dispatch automatique n’a pas pu partir

## Tickets

Le coeur métier de l’API est maintenant explicite :
- `Ticket` : objet board / produit
- `TicketTask` : unité de travail opérationnelle
- `TicketLog` : historique narratif
- `AgentTaskExecution` : historique technique d’exécution agent

### `GET /api/projects/{projectId}/tickets`
Liste les tickets du projet.

Chaque ticket expose notamment :
- `workflowStep`
- `taskCounts`
- `activeStepTasks`
- `tasks`
- `awaitingUserAnswer` / `pendingUserAnswerCount` pour signaler explicitement qu’un ticket ou l’une de ses tâches attend une réponse utilisateur

### `POST /api/projects/{projectId}/tickets`
Crée un ticket (`user_story` ou `bug`).

### `GET /api/tickets/{id}`
Retourne le détail complet d’un ticket avec :
- `tasks`
- `logs`
- `executions`
- `tokenUsage`
- `awaitingUserAnswer` / `pendingUserAnswerCount` pour signaler explicitement qu’une réponse utilisateur reste attendue

### `PUT /api/tickets/{id}`
Met à jour le ticket.

### `PATCH /api/tickets/{id}/status`
Change le statut opérationnel du ticket.

### `PATCH /api/tickets/{id}/priority`
Change la priorité du ticket.

### `POST /api/tickets/{id}/advance`
Fait avancer un ticket story/bug vers l’étape suivante de son workflow quand l’étape courante est manuelle.

Précondition :
- le ticket doit appartenir à un projet avec équipe affectée

### `POST /api/tickets/{id}/comments`
Ajoute un `TicketLog` de type commentaire.

Body :
```json
{
  "content": "Texte du commentaire",
  "replyToLogId": "uuid (optionnel) - ID du commentaire parent pour créer une réponse",
  "context": "ticket_comment | ticket_reply"
}
```

Un commentaire peut avoir des réponses. On appelle "thread" un fil de discussion qui commence par un message sans parent (`replyToLogId` null). Les réponses sont des enfants directs de ce message racine (structure plate : pas de réponses aux réponses).

### `PATCH /api/tickets/{id}/comments/{logId}`
Modifie un commentaire utilisateur existant sur le ticket.

Règles :
- seuls les commentaires/réponses `authorType=user` sont éditables
- l'édition conserve une trace minimale dans `metadata` (`editedAt`, `editCount`, `editHistory`)
- l'historique conserve les contenus précédents, sans créer un nouveau `TicketLog`

### `DELETE /api/tickets/{id}`
Supprime le ticket.

## Ticket Tasks

### `POST /api/tickets/{ticketId}/tasks`
Crée une `TicketTask` sous un ticket.

Body :
```json
{
  "title": "Implémenter l'export PDF",
  "description": "Ajouter un endpoint et le rendu du document.",
  "priority": "high",
  "actionKey": "dev.backend.implement",
  "assignedAgentId": "uuid",
  "parentTaskId": "uuid"
}
```

Si la tâche est immédiatement éligible dans l’étape courante :
- `dispatchMode=auto` : elle est dispatchée immédiatement
- `dispatchMode=manual` : elle passe en `awaiting_dispatch`

### `GET /api/ticket-tasks/{id}`
Retourne le détail complet d’une tâche opérationnelle avec :
- `dependsOn`
- `children`
- `logs`
- `executions`
- `tokenUsage`
- `awaitingUserAnswer` / `pendingUserAnswerCount` pour signaler explicitement qu’une réponse utilisateur est attendue sur cette tâche
- `canResume` pour indiquer si la tâche a déjà un historique d’exécution ou de complétion lui permettant d’être rejouée
- `canAuthorize` pour indiquer si la tâche attend encore une autorisation explicite de dispatch

### `PUT /api/ticket-tasks/{id}`
Met à jour la tâche.

### `PATCH /api/ticket-tasks/{id}/status`
Change le statut métier de la tâche.

### `PATCH /api/ticket-tasks/{id}/progress`
Met à jour la progression.

### `PATCH /api/ticket-tasks/{id}/priority`
Change la priorité.

### `POST /api/ticket-tasks/{id}/validate`
Valide la tâche.

### `POST /api/ticket-tasks/{id}/reject`
Rejette la tâche.

### `POST /api/ticket-tasks/{id}/request-validation`
Demande une validation humaine.

### `GET /api/ticket-tasks/{id}/execute`
Liste les agents disponibles pour l’`AgentAction` de la tâche.

### `POST /api/ticket-tasks/{id}/execute`
Crée une nouvelle `AgentTaskExecution` et la dispatch en asynchrone.

### `POST /api/ticket-tasks/{id}/authorize`
Autorise explicitement une tâche en `awaiting_dispatch` puis la dispatch immédiatement.

### `POST /api/ticket-tasks/{id}/resume`
Relance explicitement la tâche avec une nouvelle `AgentTaskExecution`.

Précondition commune aux routes d’exécution / reprise task-level :
- le projet du ticket doit avoir une équipe affectée
- une reprise n’est autorisée que pour une tâche déjà exécutée au moins une fois ou déjà complétée auparavant

### Execution resource snapshots

Every `AgentTaskExecutionAttempt` returned by ticket or agent execution APIs may include `resourceSnapshot`.

The snapshot is captured at runtime, just before the agent call is sent, and is meant to remain immutable even if the agent, role, skill, or workflow later change.

Captured fields:
- `agent`: database-backed agent snapshot (`id`, `name`, `description`, `connector`, `role`, `config`)
- `skill`: skill snapshot (`id`, `slug`, `name`, `source`, `originalSource`, `filePath`, `content`)
- `prompt`: `instruction`, structured `context`, and fully `rendered` prompt
- `scope`: `taskActions`, `ticketTransitions`, and backend `allowedEffects`
- `limits`: explicit capture limits such as the current absence of a dedicated agent file path

Current limits:
- agents do not have a dedicated source file, so the snapshot stores a database representation rather than an agent file path
- the snapshot reflects injected runtime resources only; it does not attempt to recursively capture every external dependency those resources may reference
- existing attempts created before this feature may expose `resourceSnapshot=null`

### `POST /api/ticket-tasks/{id}/comments`
Ajoute un commentaire contextualisé sur la tâche.

Body :
```json
{
  "content": "Texte du commentaire",
  "replyToLogId": "uuid (optionnel) - ID du commentaire parent pour créer une réponse",
  "context": "task_comment | task_reply"
}
```

Un commentaire peut avoir des réponses. Les réponses sont des enfants directs du message racine (structure plate : pas de réponses aux réponses).

### `PATCH /api/ticket-tasks/{id}/comments/{logId}`
Modifie un commentaire utilisateur existant contextualisé sur la tâche.

Règles :
- seuls les commentaires/réponses `authorType=user` sont éditables
- l'édition conserve une trace minimale dans `metadata` (`editedAt`, `editCount`, `editHistory`)
- l'historique conserve les contenus précédents, sans créer un nouveau `TicketLog`

## Chat

### `PATCH /api/projects/{projectId}/chat/{agentId}/messages/{messageId}`
Modifie un message humain existant dans la conversation projet/agent.

Règles :
- seuls les messages `author=human` sont éditables
- l'édition conserve une trace minimale dans `metadata` (`editedAt`, `editCount`, `editHistory`)
- l'édition ne rejoue pas automatiquement l'agent et ne réécrit pas les réponses déjà produites

### `DELETE /api/ticket-tasks/{id}`
Supprime la tâche.

## Logs

### `GET /api/logs/occurrences`
Liste les occurrences agrégées.

Filtres disponibles :
- `source`
- `category`
- `level`
- `projectId`
- `taskId`
- `agentId`
- `status`
- `from`
- `to`
- `page`
- `limit`

### `GET /api/logs/occurrences/{id}`
Retourne une occurrence et ses événements.

### `PATCH /api/logs/occurrences/{id}/status`
Met à jour le statut de tri.

### `GET /api/logs/events`
Liste paginée des événements bruts.

### `POST /api/logs/events`
Ingestion observabilité frontend/infra.

## Agents

### `GET /api/agents`
Liste les agents.

### `POST /api/agents`
Crée un agent.

Précondition :
- `config.model` est obligatoire

### `GET /api/agents/connectors`
Liste les connecteurs agent disponibles et leur stratégie de présélection de modèle.

Réponse :
- `connector`
- `supportsModelDiscovery`
- `selectionStrategy`

### `GET /api/agents/connectors/{connector}/models`
Retourne le catalogue normalisé des modèles pour un connecteur donné.

Query params :
- `selectedModel` : modèle actuellement choisi pour obtenir des advisories
- `refresh=1` : invalide le cache court et relance la découverte runtime

Réponse :
- `recommendedModel`
- `models[]`
- `advisories[]`
- `cached`
- `cacheTtlSeconds`

### `GET /api/agents/{id}` · `PUT /api/agents/{id}` · `DELETE /api/agents/{id}`
CRUD agent.

### `GET /api/agents/{id}/status`
Retourne le statut runtime dérivé :
- `idle`
- `working`
- `error`

Le calcul repose sur les `TicketTask` en cours et les signaux récents dans `TicketLog`.

## Autres ressources

Les ressources `teams`, `roles`, `skills`, `workflows`, `features`, `chat` et `tokens` gardent leur CRUD existant. Elles ne passent plus par un agrégat `Task` polymorphe.
