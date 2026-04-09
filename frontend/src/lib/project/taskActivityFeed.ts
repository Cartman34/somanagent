/**
 * Shared helpers for the ticket activity feed.
 *
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import type { AgentTaskExecution, TicketLog } from '@/types'
import { lookupAgentActionCatalogKey, type CatalogTranslationKey } from '@/lib/catalog'

/**
 * Translation domain used by the ticket activity feed and task detail shell.
 */
export const TASK_ACTIVITY_FEED_DOMAIN = 'app'

/**
 * Translation keys required by the ticket activity feed and drawer shell.
 */
export const TASK_ACTIVITY_FEED_TRANSLATION_KEYS = [
  'common.action.cancel',
  'common.action.close',
  'common.action.delete',
  'common.action.edit',
  'common.action.open',
  'common.action.save',
  'execution_resource.title',
  'execution_resource.captured_at',
  'execution_resource.agent',
  'execution_resource.skill',
  'execution_resource.prompt',
  'execution_resource.scope',
  'execution_resource.source',
  'execution_resource.file_path',
  'execution_resource.connector',
  'execution_resource.model',
  'execution_resource.role',
  'execution_resource.original_source',
  'execution_resource.content',
  'execution_resource.instruction',
  'execution_resource.context',
  'execution_resource.rendered_prompt',
  'execution_resource.task_actions',
  'execution_resource.ticket_transitions',
  'execution_resource.allowed_effects',
  'execution_resource.not_available',
  'execution_resource.no_agent_file',
  'ticket.detail.title',
  'ticket.detail.description_label',
  'ticket.detail.status_label',
  'ticket.detail.step_label',
  'ticket.detail.agent_label',
  'ticket.detail.action_label',
  'ticket.detail.latest_run_label',
  'ticket.detail.loading',
  'ticket.detail.expand',
  'ticket.detail.collapse',
  'ticket.detail.workflow.section_title',
  'ticket.detail.workflow.current_step',
  'ticket.detail.workflow.none',
  'ticket.detail.workflow.transition_loading',
  'ticket.detail.workflow.transition_to',
  'ticket.detail.workflow.no_transition',
  'ticket.detail.tasks_title',
  'ticket.detail.tasks_empty',
  'ticket.detail.metric.tokens_consumed',
  'ticket.detail.resume.title',
  'ticket.detail.resume.manual_help',
  'ticket.detail.resume.button',
  'ticket.detail.resume.loading',
  'ticket.detail.subtasks_title',
  'ticket.activity.title',
  'ticket.activity.description',
  'ticket.activity.empty',
  'ticket.activity.questions_pending_one',
  'ticket.activity.questions_pending_other',
  'project.progress.blocked_reason',
  'ticket.discussion.author_you',
  'ticket.discussion.author_agent',
  'ticket.discussion.edited_label',
  'ticket.discussion.edit_placeholder',
  'ticket.discussion.necessity_blocking',
  'ticket.discussion.necessity_important',
  'ticket.discussion.necessity_useful',
  'ticket.discussion.requires_answer',
  'ticket.discussion.source_action',
  'ticket.discussion.reply',
  'ticket.discussion.reply_label',
  'ticket.discussion.reply_to',
  'ticket.discussion.reply_to_fallback',
  'ticket.discussion.reply_placeholder',
  'ticket.discussion.comment_placeholder',
  'ticket.discussion.submit',
  'ticket.discussion.submit_hint',
  'ticket.discussion.send',
  'ticket.discussion.save_edit',
  'ticket.discussion.delete_reply_title',
  'ticket.discussion.delete_reply_confirm',
  'ticket.discussion.delete_reply_loading',
  'ticket.discussion.add_comment',
  'ticket.discussion.hide_replies',
  'ticket.discussion.type_above',
  'ticket.activity.event.count_one',
  'ticket.activity.event.count_other',
  'ticket.activity.event.category_execution',
  'ticket.activity.event.category_question_response',
  'ticket.activity.event.category_planning',
  'ticket.activity.event.reply_count_one',
  'ticket.activity.event.reply_count_other',
  'ticket.activity.event.detail.action',
  'ticket.activity.event.detail.created_at',
  'ticket.activity.event.detail.ticket_task_id',
  'ticket.activity.execution.title',
  'ticket.activity.execution.requested_agent',
  'ticket.activity.execution.effective_agent',
  'ticket.activity.execution.started_at',
  'ticket.activity.execution.finished_at',
  'ticket.activity.execution.attempts_count_one',
  'ticket.activity.execution.attempts_count_other',
  'ticket.activity.execution.attempt_label',
  'ticket.activity.execution.attempt_failed',
  'ticket.activity.execution.attempt_succeeded',
  'ticket.activity.execution.attempt_running',
  'ticket.activity.execution.retry_planned',
  'ticket.activity.execution.agent',
  'ticket.activity.execution.receiver',
  'ticket.activity.execution.request_ref',
  'ticket.activity.execution.error_scope',
  'ticket.activity.execution.not_available',
  'ticket.activity.execution.not_finished',
  'ticket.activity.execution.exhausted_retries',
  'ticket.activity.execution.auto',
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
