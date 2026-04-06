# Entités et modèle de données

> Voir aussi : [Architecture](architecture.md) · [API REST](api.md)

## Vue d’ensemble

```text
Project
  ├── Module
  ├── Feature
  └── Ticket
        ├── workflowStep
        ├── logs -> TicketLog[]
        └── tasks -> TicketTask[]
              ├── parentTask
              ├── workflowStep
              ├── agentAction
              ├── dependencies -> TicketTaskDependency[]
              └── executions -> AgentTaskExecution[] (ManyToMany)

AgentAction
  ├── key
  ├── label
  ├── role
  ├── skill
  └── isActive

AgentTaskExecution
  ├── agentAction
  ├── requestedAgent
  ├── effectiveAgent
  ├── attempts -> AgentTaskExecutionAttempt[]
  └── ticketTasks -> TicketTask[] (ManyToMany)
```

## Principes de responsabilité

- `Ticket` porte le suivi board / produit.
- `TicketTask` porte le travail opérationnel.
- `WorkflowStep` classe le ticket et les tâches dans le board, sans piloter directement l’exécution agent.
- `AgentAction` est un référentiel quasi immuable qui décrit l’intention exécutable.
- `AgentTaskExecution` est un historique technique indépendant.
- `TicketLog` est un historique narratif, jamais une source de vérité métier.

## Entités coeur

### Project

Projet racine.

Relations :
- `team` → `Team` (nullable)
- `modules` → `Module[]`
- `features` → `Feature[]`
- `tickets` → `Ticket[]`

Règle produit :
- un projet peut exister sans équipe
- mais toute action de progression ou d’exécution agent est bloquée tant qu’aucune équipe n’est affectée
- `dispatchMode` pilote le départ des tâches éligibles : `auto` les dispatch immédiatement, `manual` les place en attente d’autorisation

### Feature

Regroupement fonctionnel optionnel à l’intérieur d’un projet.

Relations :
- `project` → `Project`
- `tickets` → `Ticket[]` via `feature_id` nullable côté ticket

### Ticket

Objet métier visible dans le board.

Champs principaux :
- `type` : `user_story` | `bug`
- `status`
- `workflowStep`
- `priority`
- `progress`
- `branchName`

Relations :
- `project` → `Project`
- `feature` → `Feature` (nullable)
- `workflowStep` → `WorkflowStep` (nullable)
- `assignedAgent` → `Agent` (nullable)
- `assignedRole` → `Role` (nullable)
- `addedBy` → `Agent` (nullable)
- `tasks` → `TicketTask[]`
- `logs` → `TicketLog[]`

### TicketTask

Unité de travail opérationnelle.

Champs principaux :
- `title`
- `description`
- `status`
- `priority`
- `progress`
- `branchName`

Statuts notables :
- `awaiting_dispatch` = tâche éligible mais en attente d’autorisation explicite quand le projet est en mode `manual`

Relations :
- `ticket` → `Ticket`
- `parent` → `TicketTask` (nullable)
- `workflowStep` → `WorkflowStep` (nullable)
- `agentAction` → `AgentAction`
- `assignedAgent` → `Agent` (nullable)
- `assignedRole` → `Role` (nullable)
- `addedBy` → `Agent` (nullable)
- `executions` → `AgentTaskExecution[]` (ManyToMany)

Règles :
- une tâche peut exister sans exécution
- plusieurs exécutions historiques sont possibles
- une seule exécution active à la fois
- le DAG écoute le `status` de la tâche, pas les logs

### TicketTaskDependency

Table de dépendance de type DAG entre tâches opérationnelles.

Relations :
- `ticketTask`
- `dependsOn`

Règles :
- pas de dépendance vers soi-même
- pas de cycle
- une dépendance satisfaite signifie que la tâche source est `done`

### TicketLog

Historique narratif du ticket, avec contexte optionnel sur une tâche.

Champs principaux :
- `action`
- `kind`
- `content`
- `authorType`
- `authorName`
- `requiresAnswer`
- `metadata`

Relations :
- `ticket` → `Ticket`
- `ticketTask` → `TicketTask` (nullable)

### AgentAction

Référentiel d’actions agent.

Champs principaux :
- `key` unique
- `label`
- `description`
- `isActive`

Relations :
- `role` → `Role` (nullable)
- `skill` → `Skill` (nullable)

Règles :
- pas de suppression physique
- désactivation autorisée
- `key` et sémantique métier considérées comme stables

### AgentTaskExecution

Historique technique d’un lancement agent.

Champs principaux :
- `traceRef`
- `triggerType`
- `actionKey`
- `actionLabel`
- `roleSlug`
- `skillSlug`
- `status`
- `currentAttempt`
- `maxAttempts`
- `requestRef`
- `lastErrorMessage`
- `lastErrorScope`
- `startedAt`
- `finishedAt`

Relations :
- `agentAction` → `AgentAction` (nullable)
- `requestedAgent` → `Agent`
- `effectiveAgent` → `Agent`
- `attempts` → `AgentTaskExecutionAttempt[]`
- `ticketTasks` → `TicketTask[]`

Règles :
- l’exécution peut exister sans `TicketTask`
- une nouvelle exécution est créée à chaque lancement réel ou relance manuelle

### AgentTaskExecutionAttempt

Champs principaux :
- `execution`
- `attemptNumber`
- `agent`
- `status`
- `willRetry`
- `errorMessage`
- `errorScope`
- `resourceSnapshot`

Règles :
- `resourceSnapshot` stores the immutable runtime resources injected into that specific agent call
- one execution may therefore expose different snapshots across attempts if retries happen under different runtime conditions
- the agent side currently has no dedicated file path, so that part of the snapshot records an explicit limitation instead of inventing a fake file reference
Tentative individuelle d’une exécution agent.

Champs principaux :
- `attemptNumber`
- `status`
- `willRetry`
- `messengerReceiver`
- `requestRef`
- `errorMessage`
- `errorScope`
- `startedAt`
- `finishedAt`

Relations :
- `execution` → `AgentTaskExecution`
- `agent` → `Agent` (nullable)

## Entités de support

### Workflow / WorkflowStep

Définition réutilisable des étapes board.

Usage dans le modèle :
- `Ticket.workflowStep` = étape courante du ticket
- `TicketTask.workflowStep` = étape de board à laquelle la tâche est rattachée

Règles :
- `WorkflowStep` ne porte pas le routage agent direct
- le routage concret vient de `AgentAction`
- les actions autorisées par étape passent par `WorkflowStepAction`

### WorkflowStepAction

Relation entre une étape de workflow et une action agent autorisée.

Champs principaux :
- `workflowStep`
- `agentAction`
- `createWithTicket`

Règles :
- dans un même workflow, une `AgentAction` doit pointer vers une seule étape
- `createWithTicket` pré-crée la tâche à la création du ticket, même pour une étape future

### TokenUsage

Consommation de tokens liée à :
- un `Ticket`
- ou une `TicketTask`
- éventuellement une `WorkflowStep`

### AuditLog

Cross-cutting audit trail for application-level actions (entity CRUD, status transitions, lifecycle events).

Distinct from:
- `TicketLog`: narrative history scoped to a ticket, intended for display in the activity feed
- `LogEvent` / `LogOccurrence`: runtime monitoring and error tracking (Monolog)

Fields:
- `action` — enum `AuditAction` following the `<entity>.<event>` convention
- `entityType` — short class name of the affected entity (e.g. `Project`, `Ticket`, `TicketTask`)
- `entityId` — RFC 4122 UUID string of the affected entity (nullable if the entity no longer exists)
- `data` — optional JSON snapshot: before/after values or relevant parameters; shape varies by action
- `createdAt`

Key rule: `entityType` is the discriminant when the same `AuditAction` applies to multiple entity
types. For example, `task.created` covers both `Ticket` (entityType=`Ticket`) and `TicketTask`
(entityType=`TicketTask`).

Write via `AuditService::log()` only.

### ChatMessage

Conversation projet ↔ agent, indépendante du cycle ticket/task.

## Tables de jointure

- `agent_team`
- `role_skill`
- `ticket_task_agent_task_execution`
