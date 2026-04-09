/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useMemo, useState } from 'react'
import {
  Calendar,
  ChevronDown,
  Link2,
  Loader2,
  MessageSquare,
  Minus,
  Pencil,
  Plus,
  Reply,
  Send,
  Trash2,
  Zap,
} from 'lucide-react'
import Markdown from '@/components/ui/Markdown'
import ExecutionResourceSnapshot from '@/components/project/ExecutionResourceSnapshot'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { useTranslation } from '@/hooks/useTranslation'
import type { AgentTaskExecutionAttempt, TicketLog } from '@/types'
import { formatDateTime, formatTime } from '@/lib/project/constants'
import {
  TASK_ACTIVITY_FEED_DOMAIN,
  TASK_ACTIVITY_FEED_TRANSLATION_KEYS,
  buildActivityFeedEntries,
  buildActivityActionLabelKey,
  readActivityActionKey,
  getEventIconCategory,
  type ActivityFeedEntry,
  type ActivityFeedEventEntry,
  type ActivityFeedCommentEntry,
  type TaskActivityFeedTranslationKey,
  type EventIconCategory,
} from '@/lib/project/taskActivityFeed'
import {
  CATALOG_DOMAIN,
  CATALOG_TRANSLATION_KEYS,
  type CatalogTranslationKey,
} from '@/lib/catalog'

/**
 * Inline reply form placed at the end of a comment thread.
 */
function ThreadReplyForm({
  authorLabel,
  commentText,
  setCommentText,
  onSubmit,
  onCancel,
  mutationPending,
  tt,
}: {
  authorLabel: string
  commentText: string
  setCommentText: (value: string) => void
  onSubmit: () => void
  onCancel: () => void
  mutationPending: boolean
  tt: (key: TaskActivityFeedTranslationKey) => string
}) {
  return (
    <div className="inline-reply visible">
      <div className="inline-reply-header">
        <Reply className="h-3 w-3" />
        {tt('ticket.discussion.reply_to')} <strong>{authorLabel}</strong>
      </div>
      <textarea
        className="input min-h-[60px] resize-y text-sm"
        style={{ borderRadius: '6px', background: 'var(--surface2)' }}
        placeholder={tt('ticket.discussion.reply_placeholder')}
        value={commentText}
        onChange={(e) => setCommentText(e.target.value)}
        onKeyDown={(e) => {
          if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault()
            onSubmit()
          }
        }}
        autoFocus
      />
      <div className="inline-reply-actions">
        <button
          type="button"
          className="btn-send"
          style={{ padding: '5px 12px', fontSize: '12px' }}
          onClick={onSubmit}
          disabled={mutationPending || commentText.trim() === ''}
        >
          {mutationPending ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Send className="h-3.5 w-3.5" />}
          {tt('ticket.discussion.send')}
        </button>
        <button type="button" className="btn-cancel" onClick={onCancel}>
          {tt('common.action.cancel')}
        </button>
      </div>
    </div>
  )
}

interface TaskActivityFeedProps {
  logs: TicketLog[]
  executions: Parameters<typeof buildActivityFeedEntries>[1]
  commentText: string
  setCommentText: (value: string) => void
  replyToLogId: string | null
  setReplyToLogId: (value: string | null) => void
  onSubmitComment: () => void
  commentMutationPending: boolean
  editingLogId: string | null
  editingCommentText: string
  setEditingCommentText: (value: string) => void
  onStartEditLog: (log: TicketLog) => void
  onSubmitEditLog: () => void
  onCancelEditLog: () => void
  editMutationPending: boolean
  deletingLogId: string | null
  onDeleteLog: (log: TicketLog) => void
  deleteMutationPending: boolean
}

/**
 * Formats a raw metadata key into a human-readable label.
 */
function formatMetadataKey(key: string): string {
  return key
    .replace(/([A-Z])/g, ' $1')
    .replace(/[_-]/g, ' ')
    .replace(/^./, (c) => c.toUpperCase())
    .trim()
}

/**
 * Stringifies metadata values for technical detail rendering.
 */
function stringifyMetadataValue(value: unknown): string {
  if (value === null || value === undefined) {
    return ''
  }
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return String(value)
  }
}

/**
 * Resolves the display author label for a log entry.
 */
function renderAuthorLabel(log: TicketLog, tt: (key: 'ticket.discussion.author_you' | 'ticket.discussion.author_agent') => string): string {
  if (log.authorName) {
    return log.authorName
  }
  return log.authorType === 'agent' ? tt('ticket.discussion.author_agent') : tt('ticket.discussion.author_you')
}

/**
 * Returns whether one ticket log is editable by the current user.
 */
function isEditableUserLog(log: TicketLog): boolean {
  return log.kind === 'comment' && log.authorType === 'user'
}

/**
 * Returns whether a discussion item carries edit trace metadata.
 */
function isEdited(metadata: Record<string, unknown> | null): boolean {
  return typeof metadata?.editedAt === 'string'
}

/**
 * Inline edit form used for comment and reply edition.
 */
function InlineCommentEditForm({
  value,
  setValue,
  onSubmit,
  onCancel,
  pending,
  placeholder,
  submitLabel,
  tt,
}: {
  value: string
  setValue: (value: string) => void
  onSubmit: () => void
  onCancel: () => void
  pending: boolean
  placeholder: string
  submitLabel: string
  tt: (key: TaskActivityFeedTranslationKey) => string
}) {
  return (
    <div className="inline-reply visible">
      <textarea
        className="input min-h-[60px] resize-y text-sm"
        style={{ borderRadius: '6px', background: 'var(--surface2)' }}
        placeholder={placeholder}
        value={value}
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={(e) => {
          if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault()
            onSubmit()
          }
        }}
        autoFocus
      />
      <div className="inline-reply-actions">
        <button
          type="button"
          className="btn-send"
          style={{ padding: '5px 12px', fontSize: '12px' }}
          onClick={onSubmit}
          disabled={pending || value.trim() === ''}
        >
          {pending ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Send className="h-3.5 w-3.5" />}
          {submitLabel}
        </button>
        <button type="button" className="btn-cancel" onClick={onCancel}>
          {tt('common.action.cancel')}
        </button>
      </div>
    </div>
  )
}

/**
 * Resolves the translated execution status label.
 */
function executionStatusLabel(
  status: string,
  tc: (key: CatalogTranslationKey) => string,
): string {
  switch (status) {
    case 'pending':
      return tc('execution.status.pending')
    case 'running':
      return tc('execution.status.running')
    case 'retrying':
      return tc('execution.status.retrying')
    case 'succeeded':
      return tc('execution.status.succeeded')
    case 'failed':
      return tc('execution.status.failed')
    case 'dead_letter':
      return tc('execution.status.dead_letter')
    case 'cancelled':
      return tc('execution.status.cancelled')
    default:
      return status
  }
}

/**
 * Resolves the translated attempt status label.
 */
function attemptStatusLabel(
  status: AgentTaskExecutionAttempt['status'],
  tt: (key: TaskActivityFeedTranslationKey) => string,
): string {
  switch (status) {
    case 'failed':
      return tt('ticket.activity.execution.attempt_failed')
    case 'succeeded':
      return tt('ticket.activity.execution.attempt_succeeded')
    case 'running':
      return tt('ticket.activity.execution.attempt_running')
  }
}

/**
 * Resolves the translated action label for a log entry.
 */
function resolveActionLabel(
  log: TicketLog,
  execution: ActivityFeedEventEntry['group']['execution'],
  tc: (key: CatalogTranslationKey) => string,
): string {
  const actionLabelKey = buildActivityActionLabelKey(readActivityActionKey(log, execution))
  return actionLabelKey !== null ? tc(actionLabelKey) : tc('event.label.default')
}

/**
 * Resolves the dim background color for a given status color variable.
 */
function dimBgForColor(colorVar: string): string {
  return colorVar
    .replace('--brand', '--brand-dim')
    .replace('--green', '--green-dim')
    .replace('--red', '--red-dim')
    .replace('--orange', '--orange-dim')
    .replace('--purple', '--purple-dim')
    .replace('--muted', '--surface')
}

/**
 * Returns the Lucide icon component for a given event icon category.
 */
function EventIcon({ category, size = 12 }: { category: EventIconCategory; size?: number }) {
  switch (category) {
    case 'execution':
      return <Zap className="h-3 w-3" style={{ width: `${size}px`, height: `${size}px` }} />
    case 'questionResponse':
      return <MessageSquare className="h-3 w-3" style={{ width: `${size}px`, height: `${size}px` }} />
    case 'planning':
      return <Calendar className="h-3 w-3" style={{ width: `${size}px`, height: `${size}px` }} />
  }
}

function executionResourceLabels(tt: (key: TaskActivityFeedTranslationKey) => string) {
  return {
    title: tt('execution_resource.title'),
    capturedAt: tt('execution_resource.captured_at'),
    agent: tt('execution_resource.agent'),
    skill: tt('execution_resource.skill'),
    prompt: tt('execution_resource.prompt'),
    scope: tt('execution_resource.scope'),
    source: tt('execution_resource.source'),
    filePath: tt('execution_resource.file_path'),
    connector: tt('execution_resource.connector'),
    model: tt('execution_resource.model'),
    role: tt('execution_resource.role'),
    originalSource: tt('execution_resource.original_source'),
    content: tt('execution_resource.content'),
    instruction: tt('execution_resource.instruction'),
    context: tt('execution_resource.context'),
    renderedPrompt: tt('execution_resource.rendered_prompt'),
    taskActions: tt('execution_resource.task_actions'),
    ticketTransitions: tt('execution_resource.ticket_transitions'),
    allowedEffects: tt('execution_resource.allowed_effects'),
    notAvailable: tt('execution_resource.not_available'),
    noAgentFile: tt('execution_resource.no_agent_file'),
  }
}

/**
 * Renders a single execution attempt block.
 */
function ExecutionAttemptRow({
  attempt,
  locale,
  tt,
}: {
  attempt: AgentTaskExecutionAttempt
  locale?: string
  tt: (key: TaskActivityFeedTranslationKey) => string
}) {
  const tone = attempt.status === 'failed'
    ? '#dc2626'
    : attempt.status === 'succeeded'
      ? '#16a34a'
      : 'var(--text)'

  return (
    <div className="attempt-row">
      <div className="flex flex-wrap items-center gap-2 text-xs">
        <span className="attempt-status" style={{ color: tone }}>
          {tt('ticket.activity.execution.attempt_label')} {attempt.attemptNumber}{' '}
          {attemptStatusLabel(attempt.status, tt)}
        </span>
        {attempt.willRetry && (
          <span className="badge-retry">
            {tt('ticket.activity.execution.retry_planned')}
          </span>
        )}
        <span className="attempt-agent">
          {attempt.agent?.name ?? tt('ticket.activity.execution.not_available')}
        </span>
        <span className="attempt-time">
          {attempt.finishedAt
            ? formatTime(attempt.finishedAt, locale)
            : attempt.startedAt
              ? formatTime(attempt.startedAt, locale)
              : tt('ticket.activity.execution.not_finished')}
        </span>
      </div>

      <div className="mt-2 grid gap-2 md:grid-cols-2" style={{ color: 'var(--muted)' }}>
        <div>{tt('ticket.activity.execution.agent')}: {attempt.agent?.name ?? tt('ticket.activity.execution.not_available')}</div>
        <div>{tt('ticket.activity.execution.receiver')}: {attempt.messengerReceiver ?? tt('ticket.activity.execution.not_available')}</div>
        <div>{tt('ticket.activity.execution.request_ref')}: {attempt.requestRef ?? tt('ticket.activity.execution.not_available')}</div>
        <div>{tt('ticket.activity.execution.error_scope')}: {attempt.errorScope ?? tt('ticket.activity.execution.not_available')}</div>
      </div>

      {attempt.errorMessage && (
        <pre className="mt-2 whitespace-pre-wrap break-words rounded p-2 text-xs" style={{ background: 'var(--surface)', color: 'var(--muted)' }}>
          {attempt.errorMessage}
        </pre>
      )}

      <ExecutionResourceSnapshot
        snapshot={attempt.resourceSnapshot}
        labels={executionResourceLabels(tt)}
        formatDateTime={(value) => formatDateTime(value, locale)}
      />
    </div>
  )
}

/**
 * Renders a compact event row with expandable technical details.
 */
function CompactEventRow({
  entry,
  locale,
  expanded,
  onToggle,
  tc,
  tt,
}: {
  entry: ActivityFeedEventEntry
  locale?: string
  expanded: boolean
  onToggle: () => void
  tc: (key: CatalogTranslationKey) => string
  tt: (key: TaskActivityFeedTranslationKey) => string
}) {
  const group = entry.group
  const { category } = getEventIconCategory(group.labelKey)

  const categoryLabel = category === 'execution'
    ? tt('ticket.activity.event.category_execution')
    : category === 'questionResponse'
      ? tt('ticket.activity.event.category_question_response')
      : tt('ticket.activity.event.category_planning')

  const agentName = group.execution?.effectiveAgent?.name
    ?? group.execution?.requestedAgent?.name
    ?? group.logs.find((log) => log.authorName)?.authorName
    ?? ''

  return (
    <div>
      <button
        type="button"
        className={`event-row ${expanded ? 'open' : ''}`}
        onClick={onToggle}
      >
        {agentName && (
          <span className="event-agent">
            {agentName}
          </span>
        )}
        <span className="event-label">
          {tc(group.labelKey)}
        </span>
        <span className="event-category-badge">
          {categoryLabel}
        </span>
        <span className="event-time">
          {formatDateTime(group.timestamp, locale)}
        </span>
        <ChevronDown className="event-chevron h-3.5 w-3.5" />
      </button>

      {expanded && (
        <div className="event-detail visible">
          <div className="event-detail-inner anim-fade-in">
            <div className="grid grid-cols-1 gap-x-4 gap-y-2 text-xs md:grid-cols-2" style={{ color: 'var(--muted)' }}>
              <div className="flex items-baseline gap-3">
                <span className="min-w-[70px] text-right md:min-w-[110px] md:text-left">{tt('ticket.activity.event.detail.action')}</span>
                <span className="truncate font-medium" style={{ color: 'var(--text)' }}>{resolveActionLabel(group.logs[0], group.execution, tc)}</span>
              </div>
              <div className="flex items-baseline gap-3">
                <span className="min-w-[70px] text-right md:min-w-[110px] md:text-left">{tt('ticket.activity.event.detail.created_at')}</span>
                <span className="truncate font-medium" style={{ color: 'var(--text)' }}>{formatDateTime(group.timestamp, locale)}</span>
              </div>
              {group.logs.flatMap((log) =>
                log.metadata
                  ? Object.entries(log.metadata).map(([key, value]) => (
                      <div key={`${log.id}:${key}`} className="flex items-baseline gap-3">
                        <span className="min-w-[70px] text-right md:min-w-[110px] md:text-left">{formatMetadataKey(key)}</span>
                        <span className="truncate font-medium" style={{ color: 'var(--text)' }}>{stringifyMetadataValue(value)}</span>
                      </div>
                    ))
                  : [],
              )}
            </div>

            {group.execution && (
              <div className="detail-execution">
                <h4>{tt('ticket.activity.execution.title')}</h4>
                <div className="flex flex-wrap items-center gap-2">
                  <span className={`exec-status ${group.execution.status === 'running' ? 'running' : group.execution.status === 'succeeded' ? 'succeeded' : 'failed'}`}>
                    {executionStatusLabel(group.execution.status, tc)}
                  </span>
                  <span style={{ color: 'var(--muted)', marginLeft: '8px' }}>
                    {tt('ticket.activity.execution.attempts_count_other').replace('%count%', String(group.execution.maxAttempts))}
                  </span>
                  <span className="ml-auto font-mono text-[11px]" style={{ color: 'var(--muted)' }}>{group.execution.traceRef}</span>
                </div>
                <div className="attempts-list">
                  {group.execution.attempts.map((attempt) => (
                    <ExecutionAttemptRow key={attempt.id} attempt={attempt} locale={locale} tt={tt} />
                  ))}
                </div>
              </div>
            )}

            {group.logs.map((log) => {
              const actionLabel = resolveActionLabel(log, group.execution, tc)
              return (
                <div key={log.id} className="detail-log">
                  <div className="detail-log-header">
                    <span className="detail-log-author">{renderAuthorLabel(log, tt)}</span>
                    <span className="detail-log-date">{formatTime(log.createdAt, locale)}</span>
                    <span className="detail-log-action">{actionLabel}</span>
                    {log.ticketTaskId && (
                      <span className="detail-log-task">
                        {tt('ticket.activity.event.detail.ticket_task_id')}: {log.ticketTaskId}
                      </span>
                    )}
                    {log.requiresAnswer && (
                      <span className="detail-log-answer">
                        {tt('ticket.discussion.requires_answer')}
                      </span>
                    )}
                  </div>
                  {log.content && log.content.trim() !== '' && (
                    <p className="detail-log-content">
                      {log.content}
                    </p>
                  )}
                </div>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}

/**
 * Renders a comment thread card with replies and inline reply form.
 */
function CommentThreadCard({
  entry,
  locale,
  isReplying,
  commentText,
  setCommentText,
  onReply,
  onSubmitReply,
  onCancelReply,
  mutationPending,
  editingLogId,
  editingCommentText,
  setEditingCommentText,
  onStartEditLog,
  onSubmitEditLog,
  onCancelEditLog,
  editMutationPending,
  deletingLogId,
  onDeleteLog,
  deleteMutationPending,
  tc,
  tt,
}: {
  entry: ActivityFeedCommentEntry
  locale?: string
  isReplying: boolean
  commentText: string
  setCommentText: (value: string) => void
  onReply: () => void
  onSubmitReply: () => void
  onCancelReply: () => void
  mutationPending: boolean
  editingLogId: string | null
  editingCommentText: string
  setEditingCommentText: (value: string) => void
  onStartEditLog: (log: TicketLog) => void
  onSubmitEditLog: () => void
  onCancelEditLog: () => void
  editMutationPending: boolean
  deletingLogId: string | null
  onDeleteLog: (log: TicketLog) => void
  deleteMutationPending: boolean
  tc: (key: CatalogTranslationKey) => string
  tt: (key: TaskActivityFeedTranslationKey) => string
}) {
  const [repliesExpanded, setRepliesExpanded] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState<TicketLog | null>(null)
  const root = entry.thread.root
  const isAgent = root.authorType === 'agent'
  const rootActionLabel = buildActivityActionLabelKey(readActivityActionKey(root, null))
  const replyCount = entry.thread.replies.length

  return (
    <div className={`comment-card ${isAgent ? 'agent-question' : 'user-comment'}`}>
      <div className="comment-body">
        <div className="comment-meta">
          <span className="comment-author">
            {renderAuthorLabel(root, tt)}
          </span>
          {isEdited(root.metadata) && (
            <span className="reply-badge">
              {tt('ticket.discussion.edited_label')}
            </span>
          )}
          {root.requiresAnswer && (
            <span className="badge-answer">
              {tt('ticket.discussion.requires_answer')}
            </span>
          )}
          {rootActionLabel && (
            <a
              href={`#activity-log-${root.id}`}
              className="source-action"
              title={tt('ticket.discussion.source_action')}
            >
              <Link2 className="h-3 w-3" />
              {tt('ticket.discussion.source_action')}: {tc(rootActionLabel)}
            </a>
          )}
          <span className="comment-date">
            {formatDateTime(root.createdAt, locale)}
          </span>
        </div>

        {root.content && (
          <div className="comment-content">
            {editingLogId === root.id ? (
              <InlineCommentEditForm
                value={editingCommentText}
                setValue={setEditingCommentText}
                onSubmit={onSubmitEditLog}
                onCancel={onCancelEditLog}
                pending={editMutationPending}
                placeholder={tt('ticket.discussion.edit_placeholder')}
                submitLabel={tt('ticket.discussion.save_edit')}
                tt={tt}
              />
            ) : (
              <Markdown content={root.content} density="compact" />
            )}
          </div>
        )}
      </div>

      <div className="comment-footer">
        <button
          type="button"
          className="btn-reply"
          onClick={onReply}
        >
          <Reply className="h-3 w-3" />
          {tt('ticket.discussion.reply')}
        </button>
        {isEditableUserLog(root) && (
          <button
            type="button"
            className="btn-reply"
            onClick={() => onStartEditLog(root)}
          >
            <Pencil className="h-3 w-3" />
            {tt('common.action.edit')}
          </button>
        )}
      </div>

      {replyCount > 0 && (
        <>
          <button
            type="button"
            className={`replies-toggle ${repliesExpanded ? 'open' : ''}`}
            onClick={() => setRepliesExpanded(!repliesExpanded)}
          >
            <ChevronDown className="h-3 w-3" />
            <span>
              {repliesExpanded
                ? tt('ticket.discussion.hide_replies')
                : `${replyCount > 1 ? tt('ticket.activity.event.reply_count_other').replace('%count%', String(replyCount)) : tt('ticket.activity.event.reply_count_one').replace('%count%', String(replyCount))}`
              }
            </span>
          </button>

          {repliesExpanded && (
            <div className="replies-list visible">
              {entry.thread.replies.map((reply) => (
                <div
                  key={reply.id}
                  className={`reply-item ${reply.authorType === 'agent' ? 'agent-reply' : 'user-reply'}`}
                >
                  <div className="reply-meta">
                    <span className="reply-author">
                      {renderAuthorLabel(reply, tt)}
                    </span>
                    {isEdited(reply.metadata) && (
                      <span className="reply-badge">
                        {tt('ticket.discussion.edited_label')}
                      </span>
                    )}
                    <span className="reply-badge">
                      {tt('ticket.discussion.reply_label')}
                    </span>
                    <span className="reply-date">{formatDateTime(reply.createdAt, locale)}</span>
                  </div>
                  {reply.content && (
                    <div className="reply-content">
                      {editingLogId === reply.id ? (
                        <InlineCommentEditForm
                          value={editingCommentText}
                          setValue={setEditingCommentText}
                          onSubmit={onSubmitEditLog}
                          onCancel={onCancelEditLog}
                          pending={editMutationPending}
                          placeholder={tt('ticket.discussion.edit_placeholder')}
                          submitLabel={tt('ticket.discussion.save_edit')}
                          tt={tt}
                        />
                      ) : (
                        <Markdown content={reply.content} density="compact" />
                      )}
                    </div>
                  )}
                  <div className="reply-footer">
                    <button
                      type="button"
                      className="btn-reply"
                      onClick={onReply}
                    >
                      <Reply className="h-2.5 w-2.5" />
                      {tt('ticket.discussion.reply')}
                    </button>
                    {isEditableUserLog(reply) && (
                      <>
                        <button
                          type="button"
                          className="btn-reply"
                          onClick={() => onStartEditLog(reply)}
                        >
                          <Pencil className="h-2.5 w-2.5" />
                          {tt('common.action.edit')}
                        </button>
                        <button
                          type="button"
                          className="btn-reply"
                          onClick={() => setDeleteTarget(reply)}
                          disabled={deleteMutationPending}
                        >
                          <Trash2 className="h-2.5 w-2.5" />
                          {tt('common.action.delete')}
                        </button>
                      </>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </>
      )}

      {isReplying && (
        <ThreadReplyForm
          authorLabel={root.authorName ?? renderAuthorLabel(root, tt)}
          commentText={commentText}
          setCommentText={setCommentText}
          onSubmit={onSubmitReply}
          onCancel={onCancelReply}
          mutationPending={mutationPending}
          tt={tt}
        />
      )}

      <ConfirmDialog
        open={deleteTarget !== null}
        onClose={() => setDeleteTarget(null)}
        onConfirm={() => {
          if (deleteTarget !== null) {
            setDeleteTarget(null)
            onDeleteLog(deleteTarget)
          }
        }}
        title={tt('ticket.discussion.delete_reply_title')}
        message={tt('ticket.discussion.delete_reply_confirm')}
        confirmLabel={tt('common.action.delete')}
        cancelLabel={tt('common.action.cancel')}
        loadingLabel={tt('ticket.discussion.delete_reply_loading')}
        loading={deleteMutationPending && deletingLogId === deleteTarget?.id}
      />
    </div>
  )
}

/**
 * Renders the ticket activity feed with a timeline layout, compact event rows,
 * rich comment threads, and a collapsible comment composer.
 */
export default function TaskActivityFeed({
  logs,
  executions,
  commentText,
  setCommentText,
  replyToLogId,
  setReplyToLogId,
  onSubmitComment,
  commentMutationPending,
  editingLogId,
  editingCommentText,
  setEditingCommentText,
  onStartEditLog,
  onSubmitEditLog,
  onCancelEditLog,
  editMutationPending,
  deletingLogId,
  onDeleteLog,
  deleteMutationPending,
}: TaskActivityFeedProps) {
  const [expandedEventIds, setExpandedEventIds] = useState<string[]>([])
  const [composerOpen, setComposerOpen] = useState(false)

  const { t: tt, locale } = useTranslation(TASK_ACTIVITY_FEED_TRANSLATION_KEYS, TASK_ACTIVITY_FEED_DOMAIN)
  const { t: tc } = useTranslation(CATALOG_TRANSLATION_KEYS, CATALOG_DOMAIN)
  const entries = useMemo(() => buildActivityFeedEntries(logs, executions), [logs, executions])
  const commentCount = useMemo(
    () => logs.filter((log) => log.kind === 'comment' && log.requiresAnswer).length,
    [logs],
  )

  const toggleEvent = (entryId: string) => {
    setExpandedEventIds((current) => (
      current.includes(entryId)
        ? current.filter((id) => id !== entryId)
        : [...current, entryId]
    ))
  }

  const replyTargetLabel = useMemo(() => {
    if (!replyToLogId) {
      return ''
    }

    const root = logs.find((log) => log.id === replyToLogId)
    if (!root) {
      return replyToLogId
    }

    return root.authorName ?? renderAuthorLabel(root, tt)
  }, [logs, replyToLogId, tt])

  return (
    <section className="feed">
      {/* Header */}
      <div className="feed-header">
        <h2>{tt('ticket.activity.title')}</h2>
        {commentCount > 0 && (
          <span className="badge-pending">
            {commentCount === 1
              ? tt('ticket.activity.questions_pending_one')
              : tt('ticket.activity.questions_pending_other').replace('%count%', String(commentCount))}
          </span>
        )}
      </div>

      {/* Composer toggle */}
      <button
        type="button"
        className={`composer-toggle ${composerOpen ? 'open' : ''}`}
        onClick={() => setComposerOpen(!composerOpen)}
      >
        <span className="composer-toggle-label">
          <MessageSquare className="h-3.5 w-3.5" />
          {tt('ticket.discussion.add_comment')}
        </span>
        <span className="composer-toggle-indicator" aria-hidden="true">
          {composerOpen ? <Minus className="h-3.5 w-3.5" /> : <Plus className="h-3.5 w-3.5" />}
        </span>
      </button>

      {/* Composer panel */}
      {composerOpen && (
        <div className="comment-composer visible">
          <textarea
            className="input min-h-[80px] resize-y"
            placeholder={replyToLogId
              ? tt('ticket.discussion.reply_placeholder')
              : tt('ticket.discussion.comment_placeholder')
            }
            value={commentText}
            onChange={(event) => setCommentText(event.target.value)}
            onKeyDown={(event) => {
              if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                event.preventDefault()
                onSubmitComment()
              }
            }}
          />

          {replyToLogId && (
            <div className="reply-indicator">
              {tt('ticket.discussion.reply_to')} {replyTargetLabel || tt('ticket.discussion.reply_to_fallback')}
              <button
                type="button"
                className="cancel-btn"
                onClick={() => {
                  setReplyToLogId(null)
                  setCommentText('')
                }}
              >
                {tt('common.action.cancel')}
              </button>
            </div>
          )}

          <div className="composer-actions">
            <button
              type="button"
              className="btn-send"
              onClick={onSubmitComment}
              disabled={commentMutationPending || commentText.trim() === ''}
            >
              {commentMutationPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
              {tt('ticket.discussion.submit')}
            </button>
            <span className="hint">
              {tt('ticket.discussion.submit_hint')}
            </span>
          </div>
        </div>
      )}

      {/* Feed body with timeline */}
      <div className="feed-body">
        {entries.length === 0 && (
          <div className="empty-state">
            {tt('ticket.activity.empty')}
          </div>
        )}

        <div className="timeline">
          {entries.map((entry: ActivityFeedEntry) => {
            if (entry.kind === 'comment-thread') {
              const commentEntry = entry as ActivityFeedCommentEntry
              const isReplying = replyToLogId === commentEntry.thread.root.id

              return (
                <div
                  key={entry.id}
                  id={`activity-log-${commentEntry.thread.root.id}`}
                  className="timeline-entry"
                >
                  {/* Timeline icon */}
                  <div className="timeline-icon icon-comment">
                    <MessageSquare className="h-[13px] w-[13px]" />
                  </div>

                  <CommentThreadCard
                    entry={commentEntry}
                    locale={locale}
                    isReplying={isReplying}
                    commentText={commentText}
                    setCommentText={setCommentText}
                    onReply={() => {
                      setReplyToLogId(commentEntry.thread.root.id)
                      setCommentText('')
                      onCancelEditLog()
                    }}
                    onSubmitReply={onSubmitComment}
                    onCancelReply={() => {
                      setReplyToLogId(null)
                      setCommentText('')
                    }}
                    mutationPending={commentMutationPending}
                    editingLogId={editingLogId}
                    editingCommentText={editingCommentText}
                    setEditingCommentText={setEditingCommentText}
                    onStartEditLog={onStartEditLog}
                    onSubmitEditLog={onSubmitEditLog}
                    onCancelEditLog={onCancelEditLog}
                    editMutationPending={editMutationPending}
                    deletingLogId={deletingLogId}
                    onDeleteLog={onDeleteLog}
                    deleteMutationPending={deleteMutationPending}
                    tc={tc}
                    tt={tt}
                  />
                </div>
              )
            }

            const eventEntry = entry as ActivityFeedEventEntry
            const expanded = expandedEventIds.includes(eventEntry.id)

            return (
              <div
                key={entry.id}
                className="timeline-entry"
              >
                {/* Timeline icon */}
                {(() => {
                  const { category, colorVar } = getEventIconCategory(eventEntry.group.labelKey)
                  return (
                    <div
                      className="timeline-icon"
                      style={{ background: dimBgForColor(colorVar), color: colorVar }}
                    >
                      <EventIcon category={category} size={13} />
                    </div>
                  )
                })()}

                <CompactEventRow
                  entry={eventEntry}
                  locale={locale}
                  expanded={expanded}
                  onToggle={() => toggleEvent(eventEntry.id)}
                  tc={tc}
                  tt={tt}
                />
              </div>
            )
          })}
        </div>
      </div>
    </section>
  )
}
