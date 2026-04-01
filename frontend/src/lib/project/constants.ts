/**
 * Shared constants, helpers, and tab types for the project detail feature.
 *
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import type { ComponentType } from 'react'
import {
  Settings, Kanban, ListTodo, Users, Code2, History, Coins,
} from 'lucide-react'
import type {
  TaskType,
  TaskPriority,
  TaskStatus,
  AgentTaskExecution,
  Ticket,
  TicketTask,
} from '@/types'

// ─── Badge / label maps ───────────────────────────────────────────────────────

/** CSS badge class per task type. */
export const TYPE_BADGE: Record<TaskType, string> = {
  user_story: 'badge-blue',
  bug: 'badge-red',
  task: 'badge-green',
}

/** Short display label per task type. */
export const TYPE_LABELS: Record<TaskType, string> = {
  user_story: 'US',
  bug: 'Bug',
  task: 'Tâche',
}

/** Display label per task priority. */
export const PRIORITY_LABELS: Record<TaskPriority, string> = {
  low: 'Faible',
  medium: 'Normale',
  high: 'Haute',
  critical: 'Critique',
}

/** Tailwind text-color class per task priority. */
export const PRIORITY_COLOR: Record<TaskPriority, string> = {
  low: 'text-gray-400',
  medium: 'text-blue-500',
  high: 'text-orange-500',
  critical: 'text-red-600',
}

/** Display label per task status. */
export const STATUS_LABELS: Record<TaskStatus, string> = {
  backlog: 'Backlog',
  todo: 'À faire',
  in_progress: 'En cours',
  review: 'Revue',
  done: 'Terminé',
  cancelled: 'Annulé',
}

/** Human-readable label for audit log action keys. */
export const AUDIT_ACTION_LABELS: Record<string, string> = {
  'project.created': 'Projet créé',
  'project.updated': 'Projet modifié',
  'project.deleted': 'Projet supprimé',
  'task.created': 'Tâche créée',
  'task.updated': 'Tâche modifiée',
  'task.deleted': 'Tâche supprimée',
  'task.assigned': 'Tâche assignée',
  'task.status_changed': 'Statut changé',
  'task.progress_updated': 'Progression mise à jour',
  'task.validation_asked': 'Validation demandée',
  'task.validated': 'Tâche validée',
  'task.rejected': 'Tâche rejetée',
  'task.reprioritized': 'Priorité modifiée',
}

/** Display label per agent task execution status. */
export const EXECUTION_STATUS_LABELS: Record<AgentTaskExecution['status'], string> = {
  pending: 'En attente',
  running: 'En cours',
  retrying: 'En retry',
  succeeded: 'Réussie',
  failed: 'Échouée',
  dead_letter: 'Dead letter',
  cancelled: 'Annulée',
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Formats a date-time string as a short date + short time using `Intl.DateTimeFormat`.
 *
 * @param value - ISO date string.
 * @param locale - Optional BCP 47 locale tag.
 */
export function formatDateTime(value: string, locale?: string): string {
  return new Intl.DateTimeFormat(locale, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
}

/**
 * Formats a date-time string as HH:MM using `Intl.DateTimeFormat`.
 *
 * @param value - ISO date string.
 * @param locale - Optional BCP 47 locale tag.
 */
export function formatTime(value: string, locale?: string): string {
  return new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(new Date(value))
}

/**
 * Type guard — returns `true` when `entity` is a top-level `Ticket` rather than a `TicketTask`.
 *
 * @param entity - Either a Ticket or a TicketTask.
 */
export function isTicket(entity: Ticket | TicketTask): entity is Ticket {
  return 'projectId' in entity
}

// ─── Tabs ─────────────────────────────────────────────────────────────────────

/** Union of valid tab keys for the project detail page. */
export type Tab = 'general' | 'board' | 'tasks' | 'team' | 'modules' | 'audit' | 'tokens'

/** Ordered list of tabs shown in the project detail navigation bar. */
export const TABS: { key: Tab; label: string; icon: ComponentType<{ className?: string }> }[] = [
  { key: 'general', label: 'Général',  icon: Settings },
  { key: 'board',   label: 'Board',    icon: Kanban   },
  { key: 'tasks',   label: 'Tâches',   icon: ListTodo },
  { key: 'team',    label: 'Équipe',   icon: Users    },
  { key: 'modules', label: 'Modules',  icon: Code2    },
  { key: 'audit',   label: 'Audit',    icon: History  },
  { key: 'tokens',  label: 'Tokens',   icon: Coins    },
]

/** Default tab shown when no `?tab=` search param is present. */
export const DEFAULT_TAB: Tab = 'board'

/**
 * Type guard — returns `true` when `value` is a valid `Tab` key.
 *
 * @param value - Raw string from URL search params.
 */
export function isProjectTab(value: string | null): value is Tab {
  return TABS.some((tab) => tab.key === value)
}
