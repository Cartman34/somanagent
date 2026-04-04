/**
 * Shared constants, helpers, and tab types for the project detail feature.
 *
 * Label maps store translation keys rather than hardcoded strings.
 * Consumers must resolve them through a translation function (`t()` / `tt()`).
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

// ─── Translation domain & key maps ────────────────────────────────────────────

/** Translation domain identifier for project-level UI constants. */
export const PROJECT_CONSTANTS_DOMAIN = 'app'

/** Translation key per task type. */
export const TYPE_LABEL_KEYS: Record<TaskType, string> = {
  user_story: 'task.type.user_story',
  bug: 'task.type.bug',
  task: 'task.type.task',
}

/** Translation key per task priority. */
export const PRIORITY_LABEL_KEYS: Record<TaskPriority, string> = {
  low: 'task.priority.low',
  medium: 'task.priority.medium',
  high: 'task.priority.high',
  critical: 'task.priority.critical',
}

/** Translation key per task status. */
export const STATUS_LABEL_KEYS: Record<TaskStatus, string> = {
  backlog: 'task.status.backlog',
  todo: 'task.status.todo',
  in_progress: 'task.status.in_progress',
  review: 'task.status.review',
  done: 'task.status.done',
  cancelled: 'task.status.cancelled',
}

/** Translation key per audit log action. */
export const AUDIT_ACTION_LABEL_KEYS: Record<string, string> = {
  'project.created': 'audit.action.project_created',
  'project.updated': 'audit.action.project_updated',
  'project.deleted': 'audit.action.project_deleted',
  'task.created': 'audit.action.task_created',
  'task.updated': 'audit.action.task_updated',
  'task.deleted': 'audit.action.task_deleted',
  'task.status_changed': 'audit.action.task_status_changed',
  'task.progress_updated': 'audit.action.task_progress_updated',
  'task.validation_asked': 'audit.action.task_validation_asked',
  'task.validated': 'audit.action.task_validated',
  'task.rejected': 'audit.action.task_rejected',
  'task.reprioritized': 'audit.action.task_reprioritized',
}

/** Translation key per agent task execution status. */
export const EXECUTION_STATUS_LABEL_KEYS: Record<AgentTaskExecution['status'], string> = {
  pending: 'task.execution_status.pending',
  running: 'task.execution_status.running',
  retrying: 'task.execution_status.retrying',
  succeeded: 'task.execution_status.succeeded',
  failed: 'task.execution_status.failed',
  dead_letter: 'task.execution_status.dead_letter',
  cancelled: 'task.execution_status.cancelled',
}

/** All translation keys required from the project constants domain. */
export const PROJECT_CONSTANTS_TRANSLATION_KEYS = [
  ...Object.values(TYPE_LABEL_KEYS),
  ...Object.values(PRIORITY_LABEL_KEYS),
  ...Object.values(STATUS_LABEL_KEYS),
  ...Object.values(AUDIT_ACTION_LABEL_KEYS),
  ...Object.values(EXECUTION_STATUS_LABEL_KEYS),
] as const

// ─── Backward-compatible resolved-label helpers ───────────────────────────────
//
// These helpers accept a translation resolver so callers can resolve keys
// without keeping French literals in this module.

/**
 * Resolved display label per task type.
 *
 * @param t - Translation resolver function.
 */
export function TYPE_LABELS(t: (key: string) => string): Record<TaskType, string> {
  return {
    user_story: t(TYPE_LABEL_KEYS.user_story),
    bug: t(TYPE_LABEL_KEYS.bug),
    task: t(TYPE_LABEL_KEYS.task),
  }
}

/**
 * Resolved display label per task priority.
 *
 * @param t - Translation resolver function.
 */
export function PRIORITY_LABELS(t: (key: string) => string): Record<TaskPriority, string> {
  return {
    low: t(PRIORITY_LABEL_KEYS.low),
    medium: t(PRIORITY_LABEL_KEYS.medium),
    high: t(PRIORITY_LABEL_KEYS.high),
    critical: t(PRIORITY_LABEL_KEYS.critical),
  }
}

/**
 * Resolved display label per task status.
 *
 * @param t - Translation resolver function.
 */
export function STATUS_LABELS(t: (key: string) => string): Record<TaskStatus, string> {
  return {
    backlog: t(STATUS_LABEL_KEYS.backlog),
    todo: t(STATUS_LABEL_KEYS.todo),
    in_progress: t(STATUS_LABEL_KEYS.in_progress),
    review: t(STATUS_LABEL_KEYS.review),
    done: t(STATUS_LABEL_KEYS.done),
    cancelled: t(STATUS_LABEL_KEYS.cancelled),
  }
}

/**
 * Resolved human-readable label for audit log action keys.
 *
 * @param t - Translation resolver function.
 */
export function AUDIT_ACTION_LABELS(t: (key: string) => string): Record<string, string> {
  const result: Record<string, string> = {}
  for (const [actionKey, translationKey] of Object.entries(AUDIT_ACTION_LABEL_KEYS)) {
    result[actionKey] = t(translationKey)
  }
  return result
}

/**
 * Resolved display label per agent task execution status.
 *
 * @param t - Translation resolver function.
 */
export function EXECUTION_STATUS_LABELS(t: (key: string) => string): Record<AgentTaskExecution['status'], string> {
  return {
    pending: t(EXECUTION_STATUS_LABEL_KEYS.pending),
    running: t(EXECUTION_STATUS_LABEL_KEYS.running),
    retrying: t(EXECUTION_STATUS_LABEL_KEYS.retrying),
    succeeded: t(EXECUTION_STATUS_LABEL_KEYS.succeeded),
    failed: t(EXECUTION_STATUS_LABEL_KEYS.failed),
    dead_letter: t(EXECUTION_STATUS_LABEL_KEYS.dead_letter),
    cancelled: t(EXECUTION_STATUS_LABEL_KEYS.cancelled),
  }
}

// ─── Badge / color maps (no translation needed) ───────────────────────────────

/**
 * CSS badge class per task type.
 */
export const TYPE_BADGE: Record<TaskType, string> = {
  user_story: 'badge-blue',
  bug: 'badge-red',
  task: 'badge-green',
}

/**
 * Tailwind text-color class per task priority.
 */
export const PRIORITY_COLOR: Record<TaskPriority, string> = {
  low: 'text-gray-400',
  medium: 'text-blue-500',
  high: 'text-orange-500',
  critical: 'text-red-600',
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

/**
 * Union of valid tab keys for the project detail page.
 */
export type Tab = 'general' | 'board' | 'tasks' | 'team' | 'modules' | 'audit' | 'tokens'

/**
 * Ordered list of tabs shown in the project detail navigation bar.
 * Labels are resolved via translation keys (`project.detail.tabs.<key>`).
 */
export const TABS: { key: Tab; icon: ComponentType<{ className?: string }> }[] = [
  { key: 'general', icon: Settings },
  { key: 'board',   icon: Kanban   },
  { key: 'tasks',   icon: ListTodo },
  { key: 'team',    icon: Users    },
  { key: 'modules', icon: Code2    },
  { key: 'audit',   icon: History  },
  { key: 'tokens',  icon: Coins    },
]

/**
 * Default tab shown when no `?tab=` search param is present.
 */
export const DEFAULT_TAB: Tab = 'board'

/**
 * Type guard — returns `true` when `value` is a valid `Tab` key.
 *
 * @param value - Raw string from URL search params.
 */
export function isProjectTab(value: string | null): value is Tab {
  return TABS.some((tab) => tab.key === value)
}
