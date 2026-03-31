# Référence API REST

> Voir aussi : [Architecture](architecture.md) · [Entités](entities.md)

**Base URL** : `http://localhost:8080/api`
**Format** : JSON
**Authentification** : aucune en local

## Santé

### `GET /api/health`
Retourne l’état global de l’application.

### `GET /api/health/connectors`
Retourne l’état des connecteurs agent.

### `GET /api/health/claude-cli-auth`
Retourne l’état d’authentification Claude CLI.

## Projets

### `GET /api/projects`
Liste les projets.

### `POST /api/projects`
Crée un projet.

Le body peut inclure `teamId` pour affecter une équipe dès la création.

### `GET /api/projects/{id}` · `PUT /api/projects/{id}` · `DELETE /api/projects/{id}`
CRUD projet.

`PUT /api/projects/{id}` permet aussi d’affecter ou retirer l’équipe via `teamId`.

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

### `POST /api/projects/{projectId}/tickets`
Crée un ticket (`user_story` ou `bug`).

### `GET /api/tickets/{id}`
Retourne le détail complet d’un ticket avec :
- `tasks`
- `logs`
- `executions`
- `tokenUsage`

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

### `GET /api/ticket-tasks/{id}`
Retourne le détail complet d’une tâche opérationnelle avec :
- `dependsOn`
- `children`
- `logs`
- `executions`
- `tokenUsage`

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

### `POST /api/ticket-tasks/{id}/resume`
Relance explicitement la tâche avec une nouvelle `AgentTaskExecution`.

Précondition commune aux routes d’exécution / reprise task-level :
- le projet du ticket doit avoir une équipe affectée

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
