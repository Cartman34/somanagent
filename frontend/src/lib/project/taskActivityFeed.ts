/**
 * Shared helpers for the ticket activity feed.
 *
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import type { AgentTaskExecution, TicketLog } from '@/types'
import { lookupAgentActionCatalogKey, type CatalogTranslationKey } from '@/lib/catalog'

/**
 * Translation domain used by the ticket activity feed and drawer shell.
 */
export const TASK_ACTIVITY_FEED_DOMAIN = 'ticket-activity-feed'

/**
 * Translation keys required by the ticket activity feed and drawer shell.
 */
export const TASK_ACTIVITY_FEED_TRANSLATION_KEYS = [
  'common.action.cancel',
  'common.action.close',
  'drawer.title',
  'drawer.ticket.title',
  'drawer.ticket.description',
  'drawer.ticket.status',
  'drawer.ticket.step',
  'drawer.ticket.agent',
  'drawer.ticket.action',
  'drawer.ticket.latest_run',
  'drawer.loading',
  'drawer.description',
  'drawer.empty',
  'drawer.expand',
  'drawer.collapse',
  'drawer.workflow.section_title',
  'drawer.workflow.current_step',
  'drawer.workflow.none',
  'drawer.workflow.transition_loading',
  'drawer.workflow.transition_to',
  'drawer.workflow.no_transition',
  'drawer.ticket_tasks.title',
  'drawer.ticket_tasks.empty',
  'drawer.metrics.tokens_consumed',
  'drawer.metrics.questions_pending',
  'drawer.questions_pending_one',
  'drawer.questions_pending_other',
  'project.progress.blocked_reason',
  'comment.author_you',
  'comment.author_agent',
  'comment.requires_answer',
  'comment.source_action',
  'comment.reply',
  'comment.reply_label',
  'comment.reply_to',
  'comment.reply_to_fallback',
  'comment.reply_placeholder',
  'comment.comment_placeholder',
  'comment.submit',
  'comment.submit_hint',
  'comment.send',
  'comment.add_comment',
  'comment.hide_replies',
  'comment.type_above',
  'event.count_one',
  'event.count_other',
  'event.category_execution',
  'event.category_question_response',
  'event.category_planning',
  'event.reply_count_one',
  'event.reply_count_other',
  'event.detail.title',
  'event.detail.action',
  'event.detail.kind',
  'event.detail.content',
  'event.detail.created_at',
  'event.detail.ticket_task_id',
  'event.detail.metadata',
  'event.detail.open',
  'event.detail.close',
  'event.detail.linked_execution',
  'execution.title',
  'execution.requested_agent',
  'execution.effective_agent',
  'execution.started_at',
  'execution.finished_at',
  'execution.attempts_count_one',
  'execution.attempts_count_other',
  'execution.attempt_label',
  'execution.attempt_failed',
  'execution.attempt_succeeded',
  'execution.attempt_running',
  'execution.retry_planned',
  'execution.agent',
  'execution.receiver',
  'execution.request_ref',
  'execution.error_scope',
  'execution.not_available',
  'execution.not_finished',
  'execution.exhausted_retries',
  'execution.auto',
  'drawer.error.resume_failed',
  'drawer.resume.title',
  'drawer.resume.manual_help',
  'drawer.resume.button',
  'drawer.resume.loading',
  'drawer.subtasks.title',
] as const

/**
 * Union of translation keys exposed by the activity feed helper.
 */
export type TaskActivityFeedTranslationKey = typeof TASK_ACTIVITY_FEED_TRANSLATION_KEYS[number]

/**
 * Root comment and its direct replies.
 */
export interface CommentThread {
  root: TicketLog
  replies: TicketLog[]
}

/**
 * Activity feed entry representing a comment thread.
 */
export interface ActivityFeedCommentEntry {
  kind: 'comment-thread'
  id: string
  timestamp: string
  thread: CommentThread
}

/**
 * Grouped activity event entry with optional linked execution details.
 */
export interface ActivityFeedEventGroup {
  id: string
  timestamp: string
  logs: TicketLog[]
  execution: AgentTaskExecution | null
  executionId: string | null
  ticketTaskId: string | null
  labelKey: CatalogTranslationKey
  count: number
  actionKey: string | null
}

/**
 * Activity feed entry representing a grouped event.
 */
export interface ActivityFeedEventEntry {
  kind: 'event-group'
  id: string
  timestamp: string
  group: ActivityFeedEventGroup
}

/**
 * Discriminated union for every item rendered in the activity feed.
 */
export type ActivityFeedEntry = ActivityFeedCommentEntry | ActivityFeedEventEntry

/**
 * Builds comment threads from flat ticket logs.
 */
export function buildCommentThreads(logs: TicketLog[]): CommentThread[] {
  const commentLogs = logs.filter((log) => log.kind === 'comment')
  const rootLogs = commentLogs.filter((log) => !log.replyToLogId)

  return rootLogs
    .map((root) => ({
      root,
      replies: commentLogs.filter((log) => log.replyToLogId === root.id),
    }))
    .sort((left, right) => new Date(left.root.createdAt).getTime() - new Date(right.root.createdAt).getTime())
}

/**
 * Builds ordered activity feed entries by grouping comments and execution-linked logs.
 */
export function buildActivityFeedEntries(logs: TicketLog[], executions: AgentTaskExecution[]): ActivityFeedEntry[] {
  const executionById = buildExecutionByIdMap(executions)
  const entries: ActivityFeedEntry[] = []
  const commentThreads = buildCommentThreads(logs)
  const commentIds = new Set<string>()

  for (const thread of commentThreads) {
    commentIds.add(thread.root.id)
    for (const reply of thread.replies) {
      commentIds.add(reply.id)
    }
  }

  for (const thread of commentThreads) {
    entries.push({
      kind: 'comment-thread',
      id: thread.root.id,
      timestamp: thread.root.createdAt,
      thread,
    })
  }

  const eventGroups = new Map<string, ActivityFeedEventGroup>()
  const eventOrder: string[] = []
  const openGroupByTaskId = new Map<string, string>()
  const sortedLogs = [...logs].sort((left, right) => new Date(left.createdAt).getTime() - new Date(right.createdAt).getTime())

  for (const log of sortedLogs) {
    if (commentIds.has(log.id)) {
      continue
    }

    const executionId = readMetadataString(log.metadata, 'executionId')
    const ticketTaskId = log.ticketTaskId ?? null
    let groupId: string | undefined

    if (executionId !== null) {
      groupId = `execution:${executionId}`
    } else if (ticketTaskId !== null && openGroupByTaskId.has(ticketTaskId)) {
      groupId = openGroupByTaskId.get(ticketTaskId) ?? undefined
    }

    if (groupId === undefined) {
      groupId = ticketTaskId !== null
        ? `task:${ticketTaskId}:${log.id}`
        : `log:${log.id}`
      eventOrder.push(groupId)
      eventGroups.set(groupId, {
        id: groupId,
        timestamp: log.createdAt,
        logs: [log],
        execution: executionId !== null ? (executionById.get(executionId) ?? null) : null,
        executionId,
        ticketTaskId,
        labelKey: 'event.label.default',
        count: 1,
        actionKey: readActivityActionKey(log, executionId !== null ? (executionById.get(executionId) ?? null) : null),
      })
      if (ticketTaskId !== null) {
        openGroupByTaskId.set(ticketTaskId, groupId)
      }
      continue
    }

    const group = eventGroups.get(groupId)
    if (group === undefined) {
      eventOrder.push(groupId)
      eventGroups.set(groupId, {
        id: groupId,
        timestamp: log.createdAt,
        logs: [log],
        execution: executionId !== null ? (executionById.get(executionId) ?? null) : null,
        executionId,
        ticketTaskId,
        labelKey: 'event.label.default',
        count: 1,
        actionKey: readActivityActionKey(log, executionId !== null ? (executionById.get(executionId) ?? null) : null),
      })
    } else {
      group.logs.push(log)
      group.count += 1
      if (group.execution === null && executionId !== null) {
        group.execution = executionById.get(executionId) ?? null
        group.executionId = executionId
      }
      if (group.ticketTaskId === null && ticketTaskId !== null) {
        group.ticketTaskId = ticketTaskId
      }
      if (group.actionKey === null) {
        group.actionKey = readActivityActionKey(log, group.execution)
      }
    }

    if (ticketTaskId !== null) {
      openGroupByTaskId.set(ticketTaskId, groupId)
    }
  }

  for (const groupId of eventOrder) {
      const group = eventGroups.get(groupId)
    if (group !== undefined) {
      group.labelKey = buildActivityEventLabelKey(group.logs, group.execution, group.actionKey)
      entries.push({
        kind: 'event-group',
        id: group.id,
        timestamp: group.timestamp,
        group,
      })
    }
  }

  return entries.sort((left, right) => new Date(left.timestamp).getTime() - new Date(right.timestamp).getTime())
}

/**
 * Maps grouped logs and optional execution state to their catalog label key.
 */
export function buildActivityEventLabelKey(
  logs: TicketLog[],
  execution: AgentTaskExecution | null,
  actionKey: string | null,
): CatalogTranslationKey {
  const resolvedActionKey = buildActivityActionLabelKey(actionKey)
  if (resolvedActionKey !== null) {
    return resolvedActionKey
  }

  const actions = new Set(logs.map((log) => log.action))

  if (actions.has('agent_question')) {
    return 'event.label.agent_question'
  }

  if (actions.has('agent_response')) {
    return 'event.label.agent_response'
  }

  if (actions.has('execution_dispatch_error')) {
    return 'event.label.execution_dispatch_error'
  }

  if (actions.has('execution_redispatched')) {
    return 'event.label.execution_redispatched'
  }

  if (actions.has('execution_dispatched') && execution === null) {
    return 'event.label.execution_dispatched'
  }

  if (execution !== null) {
    switch (execution.status) {
      case 'pending':
        return 'event.label.execution_pending'
      case 'running':
        return 'event.label.execution_running'
      case 'retrying':
        return 'event.label.execution_retrying'
      case 'succeeded':
        return execution.attempts.length > 1
          ? 'event.label.execution_succeeded_after_retry'
          : 'event.label.execution_succeeded'
      case 'failed':
        return execution.attempts.length > 1
          ? 'event.label.execution_failed_after_retry'
          : 'event.label.execution_failed'
      case 'dead_letter':
        return 'event.label.execution_dead_letter'
      case 'cancelled':
        return 'event.label.execution_cancelled'
    }
  }

  const lastAction = logs[logs.length - 1]?.action ?? ''
  switch (lastAction) {
    case 'agent_response':
      return 'event.label.agent_response'
    case 'agent_question':
      return 'event.label.agent_question'
    case 'execution_dispatched':
      return 'event.label.execution_dispatched'
    case 'execution_redispatched':
      return 'event.label.execution_redispatched'
    case 'execution_dispatch_error':
      return 'event.label.execution_dispatch_error'
    case 'execution_started':
      return 'event.label.execution_started'
    case 'execution_failed':
      return 'event.label.execution_failed'
    case 'execution_error':
      return 'event.label.execution_error'
    case 'execution_retry':
      return 'event.label.execution_retry'
    case 'planning_completed':
      return 'event.label.planning_completed'
    case 'planning_replaced':
      return 'event.label.planning_replaced'
    case 'planning_parse_error':
      return 'event.label.planning_parse_error'
    case 'branch_prepared':
      return 'event.label.branch_prepared'
    default:
      return 'event.label.default'
  }
}

/**
 * Resolves the catalog translation key for the given agent action key.
 * Returns null if the action key is not registered in the catalog.
 *
 * @see lookupAgentActionCatalogKey
 */
export function buildActivityActionLabelKey(actionKey: string | null): CatalogTranslationKey | null {
  return lookupAgentActionCatalogKey(actionKey)
}

/**
 * Reads the best available action key for an activity group.
 */
export function readActivityActionKey(log: TicketLog, execution: AgentTaskExecution | null): string | null {
  const metadataActionKey = readMetadataString(log.metadata, 'actionKey')
  if (metadataActionKey !== null) {
    return metadataActionKey
  }

  return execution?.actionKey ?? null
}

/**
 * Reads a string metadata value when present and non-empty.
 */
export function readMetadataString(metadata: Record<string, unknown> | null, key: string): string | null {
  if (metadata === null) {
    return null
  }

  const value = metadata[key]
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

/**
 * Builds a lookup map of executions by their identifier.
 */
export function buildExecutionByIdMap(executions: AgentTaskExecution[]): Map<string, AgentTaskExecution> {
  return new Map(executions.map((execution) => [execution.id, execution] as const))
}

/**
 * Icon category for compact event rows in the activity feed.
 *
 * Three categories are used to keep the timeline visually consistent:
 * - execution: all execution-related events (zap icon, color varies by status)
 * - questionResponse: agent questions and responses (message-square icon)
 * - planning: planning-related events (calendar icon)
 */
export type EventIconCategory = 'execution' | 'questionResponse' | 'planning'

/**
 * Resolves the icon category and status color for a given labelKey.
 *
 * The icon shape stays the same within each category; the color carries
 * the granular status information.
 */
export function getEventIconCategory(
  labelKey: CatalogTranslationKey,
): { category: EventIconCategory; colorVar: string } {
  switch (labelKey) {
    case 'event.label.execution_running':
      return { category: 'execution', colorVar: 'var(--brand)' }
    case 'event.label.execution_pending':
      return { category: 'execution', colorVar: 'var(--muted)' }
    case 'event.label.execution_succeeded':
    case 'event.label.execution_succeeded_after_retry':
      return { category: 'execution', colorVar: 'var(--green)' }
    case 'event.label.execution_failed':
    case 'event.label.execution_failed_after_retry':
    case 'event.label.execution_dispatch_error':
    case 'event.label.execution_dead_letter':
      return { category: 'execution', colorVar: 'var(--red)' }
    case 'event.label.execution_retrying':
      return { category: 'execution', colorVar: 'var(--orange)' }
    case 'event.label.execution_cancelled':
      return { category: 'execution', colorVar: 'var(--muted)' }
    case 'event.label.agent_question':
      return { category: 'questionResponse', colorVar: 'var(--orange)' }
    case 'event.label.agent_response':
      return { category: 'questionResponse', colorVar: 'var(--muted)' }
    case 'event.label.planning_completed':
    case 'event.label.planning_replaced':
    case 'event.label.planning_parse_error':
      return { category: 'planning', colorVar: 'var(--purple)' }
    default:
      return { category: 'execution', colorVar: 'var(--muted)' }
  }
}
