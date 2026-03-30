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
- `storyStatus`
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

### TokenUsage

Consommation de tokens liée à :
- un `Ticket`
- ou une `TicketTask`
- éventuellement une `WorkflowStep`

### AuditLog

Trace transverse des actions applicatives.

### ChatMessage

Conversation projet ↔ agent, indépendante du cycle ticket/task.

## Tables de jointure

- `agent_team`
- `role_skill`
- `ticket_task_agent_task_execution`
