/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useEffect, useState, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  X, XCircle, CheckCircle, Clock, AlertTriangle, ChevronRight,
  GitBranch, Loader2, AlertCircle, FileText,
  ChevronDown, ChevronUp, Reply, RotateCcw, Send,
} from 'lucide-react'
import { ticketsApi, ticketTasksApi } from '@/api/tickets'
import { translationsApi } from '@/api/translations'
import type {
  Ticket,
  TicketTask,
  TicketLog,
  AgentTaskExecution,
  TaskStatus,
  TokenUsageEntry,
} from '@/types'
import EntityId from '@/components/ui/EntityId'
import Markdown from '@/components/ui/Markdown'
import {
  TYPE_BADGE, TYPE_LABELS, PRIORITY_COLOR, PRIORITY_LABELS,
  STATUS_LABELS, EXECUTION_STATUS_LABELS,
  formatDateTime, formatTime, isTicket,
} from '@/lib/project/constants'

// ─── Translation key lists (local to this component) ─────────────────────────

const EXECUTION_HISTORY_TRANSLATION_KEYS = [
  'ui.project_detail.execution_history.title',
  'ui.project_detail.execution_history.requested_agent',
  'ui.project_detail.execution_history.effective_agent',
  'ui.project_detail.execution_history.started_at',
  'ui.project_detail.execution_history.finished_at',
  'ui.project_detail.execution_history.attempts_count_one',
  'ui.project_detail.execution_history.attempts_count_other',
  'ui.project_detail.execution_history.attempt_label',
  'ui.project_detail.execution_history.attempt_failed',
  'ui.project_detail.execution_history.attempt_succeeded',
  'ui.project_detail.execution_history.attempt_running',
  'ui.project_detail.execution_history.retry_planned',
  'ui.project_detail.execution_history.agent',
  'ui.project_detail.execution_history.receiver',
  'ui.project_detail.execution_history.request_ref',
  'ui.project_detail.execution_history.error_scope',
  'ui.project_detail.execution_history.not_available',
  'ui.project_detail.execution_history.exhausted_retries',
  'ui.project_detail.execution_history.auto',
  'ui.project_detail.execution_history.not_finished',
] as const

const TICKET_DISCUSSION_TRANSLATION_KEYS = [
  'tickets.discussion.section_title',
  'tickets.discussion.description',
  'tickets.discussion.comment_placeholder',
  'tickets.discussion.submit',
  'tickets.discussion.submit_hint',
  'tickets.discussion.send',
  'tickets.discussion.empty',
  'tickets.discussion.author_you',
  'tickets.discussion.requires_answer',
  'tickets.discussion.reply',
  'tickets.discussion.reply_label',
  'tickets.discussion.reply_to',
  'tickets.discussion.reply_to_fallback',
  'tickets.discussion.reply_placeholder',
  'common.action.cancel',
] as const

const PROJECT_TEAM_GUARD_TRANSLATION_KEYS = [
  'common.action.refresh',
  'projects.progress.ui.blocked_reason',
  'projects.progress.ui.banner',
  'projects.progress.ui.rework_title',
  'projects.progress.error.request_creation_failed',
] as const

// ─── Internal helpers ─────────────────────────────────────────────────────────

function StatusIcon({ status }: { status: TaskStatus }) {
  if (status === 'done')       return <CheckCircle className="w-4 h-4 text-green-500" />
  if (status === 'cancelled')  return <XCircle className="w-4 h-4 text-gray-400" />
  if (status === 'in_progress' || status === 'review') return <Clock className="w-4 h-4 text-blue-500" />
  if (status === 'backlog')    return <AlertTriangle className="w-4 h-4 text-gray-300" />
  return <ChevronRight className="w-4 h-4 text-gray-400" />
}

// ─── Types ────────────────────────────────────────────────────────────────────

/**
 * Represents a comment thread rooted at a parent-less log entry.
 */
interface CommentThread {
  /** Root message of the thread (no parent). */
  root: TicketLog
  /** Direct replies to the root message (flat list — no nested replies). */
  replies: TicketLog[]
}

// ─── Component ────────────────────────────────────────────────────────────────

/**
 * Right-side drawer showing the full detail of a task: description, workflow step,
 * execution logs, subtasks (children), and token consumption.
 * Fetches the explicit ticket or ticket-task endpoint, then shows logs, executions and token usage.
 * The component calls itself recursively when a linked task is opened.
 *
 * @see AgentTaskExecution
 * @see CommentThread
 */
export default function TaskDrawer({ taskId, onClose, projectHasTeam }: {
  taskId: string
  onClose: () => void
  projectHasTeam: boolean
}) {
  const qc = useQueryClient()
  const [logsExpanded, setLogsExpanded] = useState(true)
  const [expandedExecutionIds, setExpandedExecutionIds] = useState<string[]>([])
  const [expandedAttemptIds, setExpandedAttemptIds] = useState<string[]>([])
  const [dismissedErrorLogId, setDismissedErrorLogId] = useState<string | null>(null)
  const [commentText, setCommentText] = useState('')
  const [replyToLogId, setReplyToLogId] = useState<string | null>(null)
  const [linkedTaskId, setLinkedTaskId] = useState<string | null>(null)
  const [taskDispatchError, setTaskDispatchError] = useState<string | null>(null)

  const { data: entity, isLoading } = useQuery<Ticket | TicketTask>({
    queryKey: ['task-detail', taskId],
    queryFn: async () => {
      try {
        return await ticketsApi.get(taskId)
      } catch {
        return await ticketTasksApi.get(taskId)
      }
    },
  })

  const commentMutation = useMutation({
    mutationFn: () => (
      entity && isTicket(entity)
        ? ticketsApi.comment(taskId, {
          content: commentText.trim(),
          replyToLogId: replyToLogId ?? undefined,
          context: replyToLogId ? 'ticket_reply' : 'ticket_comment',
        })
        : ticketTasksApi.comment(taskId, {
          content: commentText.trim(),
          replyToLogId: replyToLogId ?? undefined,
          context: replyToLogId ? 'ticket_reply' : 'ticket_comment',
        })
    ),
    onSuccess: async () => {
      setCommentText('')
      setReplyToLogId(null)
      await qc.invalidateQueries({ queryKey: ['task-detail', taskId] })
      await qc.invalidateQueries({ queryKey: ['tickets'] })
    },
  })

  const advanceMutation = useMutation({
    mutationFn: (ticketId: string) => ticketsApi.advance(ticketId),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: ['task-detail', taskId] })
      await qc.invalidateQueries({ queryKey: ['tickets'] })
    },
  })

  const taskResumeMutation = useMutation({
    mutationFn: (linkedId: string) => ticketTasksApi.resume(linkedId),
    onSuccess: async (_, linkedId) => {
      setTaskDispatchError(null)
      await qc.invalidateQueries({ queryKey: ['task-detail', taskId] })
      await qc.invalidateQueries({ queryKey: ['task-detail', linkedId] })
      await qc.invalidateQueries({ queryKey: ['tickets'] })
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { error?: string } } })?.response?.data?.error
      setTaskDispatchError(msg ?? 'Impossible de relancer la tâche.')
    },
  })

  useEffect(() => {
    setExpandedExecutionIds([])
    setExpandedAttemptIds([])
    setDismissedErrorLogId(null)
    setCommentText('')
    setReplyToLogId(null)
    setLinkedTaskId(null)
    setTaskDispatchError(null)
  }, [taskId])

  const toggleExecution = (executionId: string) => {
    setExpandedExecutionIds((current) => (
      current.includes(executionId)
        ? current.filter((id) => id !== executionId)
        : [...current, executionId]
    ))
  }

  const toggleAttempt = (attemptId: string) => {
    setExpandedAttemptIds((current) => (
      current.includes(attemptId)
        ? current.filter((id) => id !== attemptId)
        : [...current, attemptId]
    ))
  }

  const submitComment = () => {
    if (commentText.trim() === '') return
    commentMutation.mutate()
  }

  const { data: executionHistoryI18n } = useQuery({
    queryKey: ['ui-translations', 'project-detail-execution-history'],
    queryFn: () => translationsApi.list([...EXECUTION_HISTORY_TRANSLATION_KEYS]),
    staleTime: Infinity,
  })
  const { data: projectTeamGuardI18n } = useQuery({
    queryKey: ['ui-translations', 'project-team-guard'],
    queryFn: () => translationsApi.list([...PROJECT_TEAM_GUARD_TRANSLATION_KEYS]),
    staleTime: Infinity,
  })
  const { data: ticketDiscussionI18n } = useQuery({
    queryKey: ['ui-translations', 'ticket-discussion'],
    queryFn: () => translationsApi.list([...TICKET_DISCUSSION_TRANSLATION_KEYS]),
    staleTime: Infinity,
  })

  const executionHistoryLocale = executionHistoryI18n?.locale

  const et = (key: typeof EXECUTION_HISTORY_TRANSLATION_KEYS[number]) => (
    executionHistoryI18n?.translations[key] ?? key
  )
  const tt = (key: typeof PROJECT_TEAM_GUARD_TRANSLATION_KEYS[number]) => (
    projectTeamGuardI18n?.translations[key] ?? key
  )
  const dt = (key: typeof TICKET_DISCUSSION_TRANSLATION_KEYS[number]) => (
    ticketDiscussionI18n?.translations[key] ?? key
  )

  const taskHasActiveExecution = entity && !isTicket(entity)
    ? (entity.executions ?? []).some((execution) => ['pending', 'running', 'retrying'].includes(execution.status))
    : false

  // ── Derived comment data (must be outside IIFE for hooks rules) ───────────────

  const logs = entity?.logs ?? []
  const commentLogs = logs.filter((log) => log.kind === 'comment')

  /**
   * Groups comment logs into threads: each thread has one root and its direct replies.
   */
  const commentThreads = useMemo((): CommentThread[] => {
    const threads: CommentThread[] = []
    const rootLogs = commentLogs.filter((log) => !log.replyToLogId)

    for (const root of rootLogs) {
      const replies = commentLogs.filter((log) => log.replyToLogId === root.id)
      threads.push({ root, replies })
    }

    return threads.sort((a, b) =>
      new Date(a.root.createdAt).getTime() - new Date(b.root.createdAt).getTime()
    )
  }, [commentLogs])

  // ── Render ────────────────────────────────────────────────────────────────────

  const renderEntityContent = (entityData: Ticket | TicketTask) => {
    const entityLogs = entityData.logs ?? []
    const executions = entityData.executions ?? []
    const childItems = isTicket(entityData) ? (entityData.tasks ?? []) : (entityData.children ?? [])
    const ticketTasks = isTicket(entityData)
      ? [...(entityData.tasks ?? [])].sort((left, right) => {
          const leftStep = left.workflowStep?.name ?? ''
          const rightStep = right.workflowStep?.name ?? ''
          if (leftStep !== rightStep) return leftStep.localeCompare(rightStep, 'fr')
          return left.title.localeCompare(right.title, 'fr')
        })
      : []

    const totalTokens = (entityData.tokenUsage ?? []).reduce((sum, entry) => sum + entry.totalTokens, 0)
    const totalCalls = entityData.tokenUsage?.length ?? 0
    const pendingQuestions = commentLogs.filter((log) => log.requiresAnswer).length
    const latestExecutionErrorIndex = [...entityLogs].map((log, index) => ({ log, index })).reverse().find(({ log }) => log.action === 'execution_error')?.index ?? -1
    const latestExecutionError = latestExecutionErrorIndex >= 0 ? entityLogs[latestExecutionErrorIndex] : null
    const hasSuccessAfterLatestError = latestExecutionErrorIndex >= 0
      ? entityLogs.slice(latestExecutionErrorIndex + 1).some((log) => {
          if (log.action === 'agent_response') return true
          if (log.action.endsWith('_completed')) return true
          if (log.action === 'validated') return true
          if (log.action === 'status_changed' && (log.content ?? '').includes('→ done')) return true
          return false
        })
      : false
    const activeExecutionError = latestExecutionError !== null && !hasSuccessAfterLatestError
      ? latestExecutionError
      : null
    const showExecutionErrorBanner = activeExecutionError !== null && activeExecutionError.id !== dismissedErrorLogId

    return (
      <div className={`${isTicket(entityData) ? 'item-ticket' : 'item-ticket-task'} flex-1 min-h-0 overflow-y-auto p-5 space-y-5`}>
        {showExecutionErrorBanner && activeExecutionError && (
          <div className="px-3 py-2 rounded border text-sm" style={{ background: 'rgba(239,68,68,0.1)', color: '#dc2626', borderColor: 'rgba(239,68,68,0.3)' }}>
            <div className="flex items-center gap-2 font-medium">
              <AlertCircle className="w-4 h-4 flex-shrink-0" />
              <span>Dernière erreur d'exécution agent</span>
              <button
                type="button"
                className="ml-auto"
                onClick={() => setDismissedErrorLogId(activeExecutionError.id)}
                aria-label="Fermer l'erreur"
              >
                <XCircle className="w-4 h-4" />
              </button>
            </div>
            <p className="mt-1 whitespace-pre-wrap break-words">{activeExecutionError.content ?? 'Erreur inconnue.'}</p>
          </div>
        )}
        {/* Badges */}
        <div className="flex flex-wrap gap-2">
          <span className={`${TYPE_BADGE[isTicket(entityData) ? entityData.type : 'task']} text-xs`}>{TYPE_LABELS[isTicket(entityData) ? entityData.type : 'task']}</span>
          <span className={`text-xs font-medium ${PRIORITY_COLOR[entityData.priority]}`}>{PRIORITY_LABELS[entityData.priority]}</span>
          {isTicket(entityData) && entityData.workflowStep && (
            <span className="badge-blue text-xs">{entityData.workflowStep.name}</span>
          )}
          <span className="text-xs" style={{ color: 'var(--muted)' }}>{STATUS_LABELS[entityData.status]}</span>
        </div>

        <div>
          <EntityId id={entityData.id} />
        </div>

        <div className="grid gap-3 md:grid-cols-3">
          <div className="rounded border px-4 py-3" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
            <p className="text-xs" style={{ color: 'var(--muted)' }}>Tokens consommés</p>
            <p className="mt-1 text-lg font-semibold" style={{ color: 'var(--text)' }}>{totalTokens.toLocaleString()} tok</p>
            <p className="text-xs" style={{ color: 'var(--muted)' }}>{totalCalls} appel{totalCalls > 1 ? 's' : ''}</p>
          </div>
          <div className="rounded border px-4 py-3" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
            <p className="text-xs" style={{ color: 'var(--muted)' }}>Questions en attente</p>
            <p className="mt-1 text-lg font-semibold" style={{ color: 'var(--text)' }}>{pendingQuestions}</p>
            <p className="text-xs" style={{ color: 'var(--muted)' }}>Commentaires agent à traiter</p>
          </div>
          <div className="rounded border px-4 py-3" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
            <p className="text-xs" style={{ color: 'var(--muted)' }}>Relance agent</p>
            {isTicket(entityData) ? (
              <p className="mt-2 text-xs" style={{ color: 'var(--muted)' }}>
                La relance manuelle se fait désormais au niveau des tâches.
              </p>
            ) : (
              <>
                <button
                  type="button"
                  className="mt-2 inline-flex items-center gap-2 rounded px-3 py-2 text-sm"
                  style={{ background: 'var(--brand-dim)', color: 'var(--brand)' }}
                  onClick={() => taskResumeMutation.mutate(entityData.id)}
                  disabled={!projectHasTeam || taskHasActiveExecution || taskResumeMutation.isPending}
                  title={!projectHasTeam ? tt('projects.progress.ui.blocked_reason') : undefined}
                >
                  <RotateCcw className="h-4 w-4" />
                  {taskResumeMutation.isPending ? 'Relance…' : 'Relancer la tâche'}
                </button>
                {taskDispatchError && (
                  <p className="mt-2 text-xs" style={{ color: '#dc2626' }}>{taskDispatchError}</p>
                )}
              </>
            )}
          </div>
        </div>

        {isTicket(entityData) && (
          <div className="rounded border p-4" style={{ borderColor: 'var(--border)', background: 'color-mix(in srgb, var(--surface2) 82%, transparent)' }}>
            <div className="flex items-center justify-between gap-3">
              <div>
                <p className="text-sm font-medium" style={{ color: 'var(--text)' }}>Progression du ticket</p>
                <p className="text-xs" style={{ color: 'var(--muted)' }}>
                  Étape courante : {entityData.workflowStep?.name ?? 'Aucune'}
                </p>
              </div>
              {entityData.workflowStepAllowedTransitions.length > 0 && (
                <button
                  type="button"
                  className="btn-primary"
                  onClick={() => advanceMutation.mutate(entityData.id)}
                  disabled={!projectHasTeam || advanceMutation.isPending}
                  title={!projectHasTeam ? tt('projects.progress.ui.blocked_reason') : undefined}
                >
                  {advanceMutation.isPending ? 'Transition…' : `Passer à ${entityData.workflowStepAllowedTransitions[0]?.name ?? 'la suite'}`}
                </button>
              )}
            </div>
            {entityData.workflowStepAllowedTransitions.length === 0 && (
              <p className="mt-3 text-xs" style={{ color: 'var(--muted)' }}>
                Aucun passage manuel disponible depuis cette étape.
              </p>
            )}
          </div>
        )}

        {/* Description */}
        {entityData.description && (
          <div>
            <p className="text-xs font-medium mb-1" style={{ color: 'var(--muted)' }}>Description</p>
            <div className="rounded border p-4" style={{ borderColor: 'var(--border)', background: 'color-mix(in srgb, var(--surface2) 82%, transparent)' }}>
              <Markdown content={entityData.description} className="text-sm" />
            </div>
          </div>
        )}

        {/* Branch */}
        {entityData.branchName && (
          <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--muted)' }}>
            <GitBranch className="w-3.5 h-3.5 flex-shrink-0" />
            {entityData.branchUrl ? (
              <a href={entityData.branchUrl} target="_blank" rel="noreferrer" className="hover:underline" style={{ color: 'var(--brand)' }}>
                <code className="font-mono">{entityData.branchName}</code>
              </a>
            ) : (
              <code className="font-mono">{entityData.branchName}</code>
            )}
          </div>
        )}

        {isTicket(entityData) && (
          <div>
            <p className="text-xs font-medium mb-2" style={{ color: 'var(--muted)' }}>Tâches du ticket ({ticketTasks.length})</p>
            {ticketTasks.length === 0 ? (
              <div className="rounded border border-dashed px-4 py-4 text-sm" style={{ borderColor: 'var(--border)', color: 'var(--muted)' }}>
                Aucune tâche liée à ce ticket pour l'instant.
              </div>
            ) : (
              <div className="list-ticket-task space-y-2">
                {ticketTasks.map((task) => (
                  <div
                    key={task.id}
                    className="item-ticket-task w-full rounded border px-3 py-3 text-left"
                    style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}
                  >
                    <div className="flex items-center justify-between gap-3">
                      <button
                        type="button"
                        className="min-w-0 flex-1 text-left"
                        onClick={() => setLinkedTaskId(task.id)}
                      >
                        <div className="flex items-center gap-2 flex-wrap">
                          <StatusIcon status={task.status} />
                          <span className="text-sm font-medium" style={{ color: 'var(--text)' }}>{task.title}</span>
                          {task.workflowStep && (
                            <span className="badge-blue text-xs">{task.workflowStep.name}</span>
                          )}
                        </div>
                      </button>
                      <button
                        type="button"
                        className="btn-secondary text-xs"
                        onClick={() => setLinkedTaskId(task.id)}
                      >
                        Ouvrir
                      </button>
                    </div>
                    <div className="mt-1 flex items-center gap-3 flex-wrap text-xs" style={{ color: 'var(--muted)' }}>
                      <span>{STATUS_LABELS[task.status]}</span>
                      <span>{task.agentAction.label}</span>
                      {task.assignedAgent && <span>→ {task.assignedAgent.name}</span>}
                      {task.dependsOn.length > 0 && <span>{task.dependsOn.length} dépendance{task.dependsOn.length > 1 ? 's' : ''}</span>}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Subtasks */}
        {!isTicket(entityData) && childItems.length > 0 && (
          <div>
            <p className="text-xs font-medium mb-2" style={{ color: 'var(--muted)' }}>Sous-tâches ({childItems.length})</p>
            <div className="list-ticket-task space-y-1">
              {childItems.map((c) => (
                <div key={c.id} className="item-ticket-task flex items-center gap-2 text-xs px-3 py-2 rounded" style={{ background: 'var(--surface2)' }}>
                  <StatusIcon status={c.status} />
                  <span className="truncate" style={{ color: 'var(--text)' }}>{c.title}</span>
                  <span className="ml-auto flex-shrink-0" style={{ color: 'var(--muted)' }}>{PRIORITY_LABELS[c.priority]}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {executions.length > 0 && (
          <div>
            <p className="text-xs font-medium mb-2" style={{ color: 'var(--muted)' }}>
              {et('ui.project_detail.execution_history.title')} ({executions.length})
            </p>
            <div className="list-agent-execution space-y-2 max-h-96 overflow-y-auto pr-1">
              {executions.map((execution: AgentTaskExecution) => (
                <div key={execution.id} className="item-agent-execution rounded border p-4" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
                  <button
                    type="button"
                    className="flex w-full flex-wrap items-center gap-2 text-left text-xs"
                    onClick={() => toggleExecution(execution.id)}
                  >
                    <span className="rounded px-2 py-1" style={{ background: 'var(--surface)', color: 'var(--text)' }}>
                      {EXECUTION_STATUS_LABELS[execution.status]}
                    </span>
                    <span style={{ color: 'var(--muted)' }}>
                      {execution.currentAttempt}/{execution.maxAttempts} {execution.maxAttempts > 1
                        ? et('ui.project_detail.execution_history.attempts_count_other')
                        : et('ui.project_detail.execution_history.attempts_count_one')}
                    </span>
                    <span style={{ color: 'var(--muted)' }}>
                      {execution.triggerType}
                    </span>
                    {execution.skillSlug && (
                      <span style={{ color: 'var(--muted)' }}>
                        {execution.skillSlug}
                      </span>
                    )}
                    <span className="ml-auto font-mono" style={{ color: 'var(--muted)' }}>
                      {execution.traceRef}
                    </span>
                    {expandedExecutionIds.includes(execution.id)
                      ? <ChevronUp className="h-3.5 w-3.5" style={{ color: 'var(--muted)' }} />
                      : <ChevronDown className="h-3.5 w-3.5" style={{ color: 'var(--muted)' }} />
                    }
                  </button>

                  {expandedExecutionIds.includes(execution.id) && (
                    <>
                      <div className="mt-3 grid gap-3 md:grid-cols-2 text-xs" style={{ color: 'var(--muted)' }}>
                        <div>
                          {et('ui.project_detail.execution_history.requested_agent')} : {execution.requestedAgent?.name ?? et('ui.project_detail.execution_history.auto')}
                        </div>
                        <div>
                          {et('ui.project_detail.execution_history.effective_agent')} : {execution.effectiveAgent?.name ?? et('ui.project_detail.execution_history.not_available')}
                        </div>
                        <div>
                          {et('ui.project_detail.execution_history.started_at')} : {execution.startedAt ? formatDateTime(execution.startedAt, executionHistoryLocale) : et('ui.project_detail.execution_history.not_available')}
                        </div>
                        <div>
                          {et('ui.project_detail.execution_history.finished_at')} : {execution.finishedAt ? formatDateTime(execution.finishedAt, executionHistoryLocale) : et('ui.project_detail.execution_history.not_finished')}
                        </div>
                      </div>

                      <div className="mt-3 space-y-2 border-l-2 pl-3" style={{ borderColor: 'var(--border)' }}>
                        {execution.attempts.map((attempt) => {
                          const tone = attempt.status === 'failed' ? '#dc2626' : attempt.status === 'succeeded' ? '#16a34a' : 'var(--text)'
                          const isAttemptExpanded = expandedAttemptIds.includes(attempt.id)
                          return (
                            <div key={attempt.id} className="text-xs">
                              <button
                                type="button"
                                className="flex w-full flex-wrap items-center gap-2 text-left"
                                onClick={() => toggleAttempt(attempt.id)}
                              >
                                <span className="font-medium" style={{ color: tone }}>
                                  {et('ui.project_detail.execution_history.attempt_label')} {attempt.attemptNumber} {attempt.status === 'failed'
                                    ? et('ui.project_detail.execution_history.attempt_failed')
                                    : attempt.status === 'succeeded'
                                      ? et('ui.project_detail.execution_history.attempt_succeeded')
                                      : et('ui.project_detail.execution_history.attempt_running')}
                                </span>
                                {attempt.willRetry && (
                                  <span className="rounded px-2 py-0.5" style={{ background: 'rgba(245,158,11,0.14)', color: '#b45309' }}>
                                    {et('ui.project_detail.execution_history.retry_planned')}
                                  </span>
                                )}
                                {attempt.messengerReceiver && (
                                  <span style={{ color: 'var(--muted)' }}>
                                    {attempt.messengerReceiver}
                                  </span>
                                )}
                                <span className="ml-auto" style={{ color: 'var(--muted)' }}>
                                  {attempt.finishedAt
                                    ? formatTime(attempt.finishedAt, executionHistoryLocale)
                                    : attempt.startedAt
                                      ? formatTime(attempt.startedAt, executionHistoryLocale)
                                      : et('ui.project_detail.execution_history.not_finished')}
                                </span>
                                {isAttemptExpanded
                                  ? <ChevronUp className="h-3.5 w-3.5" style={{ color: 'var(--muted)' }} />
                                  : <ChevronDown className="h-3.5 w-3.5" style={{ color: 'var(--muted)' }} />
                                }
                              </button>
                              {isAttemptExpanded && (
                                <div className="mt-2 space-y-2 rounded border p-3" style={{ borderColor: 'var(--border)', background: 'var(--surface)' }}>
                                  <div className="grid gap-2 md:grid-cols-2" style={{ color: 'var(--muted)' }}>
                                    <div>{et('ui.project_detail.execution_history.agent')} : {attempt.agent?.name ?? et('ui.project_detail.execution_history.not_available')}</div>
                                    <div>{et('ui.project_detail.execution_history.receiver')} : {attempt.messengerReceiver ?? et('ui.project_detail.execution_history.not_available')}</div>
                                    <div>{et('ui.project_detail.execution_history.request_ref')} : {attempt.requestRef ?? et('ui.project_detail.execution_history.not_available')}</div>
                                    <div>{et('ui.project_detail.execution_history.error_scope')} : {attempt.errorScope ?? et('ui.project_detail.execution_history.not_available')}</div>
                                    <div>
                                      {et('ui.project_detail.execution_history.started_at')} : {attempt.startedAt ? formatDateTime(attempt.startedAt, executionHistoryLocale) : et('ui.project_detail.execution_history.not_available')}
                                    </div>
                                    <div>
                                      {et('ui.project_detail.execution_history.finished_at')} : {attempt.finishedAt ? formatDateTime(attempt.finishedAt, executionHistoryLocale) : et('ui.project_detail.execution_history.not_available')}
                                    </div>
                                  </div>
                                  {attempt.errorMessage && (
                                    <pre className="overflow-x-auto rounded p-2 whitespace-pre-wrap break-words" style={{ background: 'var(--surface2)', color: 'var(--muted)' }}>
                                      {attempt.errorMessage}
                                    </pre>
                                  )}
                                </div>
                              )}
                            </div>
                          )
                        })}
                      </div>

                      {execution.status === 'dead_letter' && (
                        <p className="mt-3 text-xs" style={{ color: '#dc2626' }}>
                          {et('ui.project_detail.execution_history.exhausted_retries')}
                        </p>
                      )}
                    </>
                  )}
                </div>
              ))}
            </div>
          </div>
        )}

        <div className="grid gap-5 xl:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]">
          <div className="space-y-4">
            <div className="rounded border p-4" style={{ borderColor: 'var(--border)', background: 'color-mix(in srgb, var(--surface2) 82%, transparent)' }}>
              <div className="flex items-center justify-between gap-3">
                <div>
                  <p className="text-sm font-medium" style={{ color: 'var(--text)' }}>{dt('tickets.discussion.section_title')}</p>
                  <p className="text-xs" style={{ color: 'var(--muted)' }}>{dt('tickets.discussion.description')}</p>
                </div>
              </div>

              <div className="mt-3 space-y-3">
                <textarea
                  className="input min-h-[110px] resize-y"
                  placeholder={dt('tickets.discussion.comment_placeholder')}
                  value={commentText}
                  onChange={(e) => setCommentText(e.target.value)}
                  onKeyDown={(e) => {
                    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                      e.preventDefault()
                      submitComment()
                    }
                  }}
                />
                <div className="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    className="inline-flex items-center gap-2 rounded px-3 py-2 text-sm"
                    style={{ background: 'var(--brand)', color: 'white' }}
                    onClick={submitComment}
                    disabled={commentMutation.isPending || commentText.trim() === ''}
                  >
                    {commentMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                    {dt('tickets.discussion.submit')}
                  </button>
                  <span className="text-xs" style={{ color: 'var(--muted)' }}>{dt('tickets.discussion.submit_hint')}</span>
                </div>
              </div>
            </div>

            <div className="space-y-4">
              {commentThreads.length === 0 && (
                <div className="rounded border border-dashed px-4 py-6 text-sm" style={{ borderColor: 'var(--border)', color: 'var(--muted)' }}>
                  {dt('tickets.discussion.empty')}
                </div>
              )}

              {commentThreads.map((thread) => {
                const root = thread.root
                const isAgent = root.authorType === 'agent'
                const context = typeof root.metadata?.context === 'string' ? root.metadata.context : null
                const isReplying = replyToLogId === root.id
                const replyingToLog = isReplying ? (replyToLogId === root.id ? root : thread.replies.find((r) => r.id === replyToLogId) ?? null) : null

                return (
                  <div key={root.id} className="list-ticket-log space-y-2">
                    <div className="item-ticket-log rounded border p-4" style={{ borderColor: 'var(--border)', background: isAgent ? 'color-mix(in srgb, var(--brand-dim) 34%, var(--surface) 66%)' : 'var(--surface2)' }}>
                      <div className="flex flex-wrap items-center gap-2 text-xs">
                        <span className="font-medium" style={{ color: 'var(--text)' }}>{root.authorName ?? (isAgent ? 'Agent' : dt('tickets.discussion.author_you'))}</span>
                        <span style={{ color: 'var(--muted)' }}>{new Date(root.createdAt).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}</span>
                        {context && <span className="rounded px-2 py-0.5" style={{ background: 'var(--surface)', color: 'var(--muted)' }}>{context}</span>}
                        {root.requiresAnswer && <span className="rounded px-2 py-0.5" style={{ background: 'rgba(245,158,11,0.14)', color: '#b45309' }}>{dt('tickets.discussion.requires_answer')}</span>}
                      </div>

                      {root.content && (
                        <div className="mt-3 text-sm">
                          <Markdown content={root.content} />
                        </div>
                      )}

                      <div className="mt-3 flex justify-end">
                        <button
                          type="button"
                          className="inline-flex items-center gap-1 rounded px-2.5 py-1.5 text-xs"
                          style={{ background: 'var(--surface)', color: 'var(--muted)' }}
                          onClick={() => { setReplyToLogId(root.id); setCommentText('') }}
                        >
                          <Reply className="w-3 h-3" />
                          {dt('tickets.discussion.reply')}
                        </button>
                      </div>
                    </div>

                    {thread.replies.map((reply) => {
                      const isReplyAgent = reply.authorType === 'agent'
                      return (
                        <div key={reply.id} className="item-ticket-log ml-6 rounded border p-3" style={{ borderColor: 'var(--border)', background: isReplyAgent ? 'color-mix(in srgb, var(--brand-dim) 25%, var(--surface) 75%)' : 'var(--surface)' }}>
                          <div className="flex flex-wrap items-center gap-2 text-xs">
                            <span className="font-medium" style={{ color: 'var(--text)' }}>{reply.authorName ?? (isReplyAgent ? 'Agent' : dt('tickets.discussion.author_you'))}</span>
                            <span style={{ color: 'var(--muted)' }}>{new Date(reply.createdAt).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}</span>
                            <span className="rounded px-2 py-0.5" style={{ background: 'var(--surface2)', color: 'var(--muted)' }}>{dt('tickets.discussion.reply_label')}</span>
                          </div>
                          {reply.content && (
                            <div className="mt-2 text-sm">
                              <Markdown content={reply.content} />
                            </div>
                          )}
                          <div className="mt-2 flex justify-end">
                            <button
                              type="button"
                              className="inline-flex items-center gap-1 rounded px-2.5 py-1 text-xs"
                              style={{ background: 'var(--surface2)', color: 'var(--muted)' }}
                              onClick={() => { setReplyToLogId(root.id); setCommentText('') }}
                            >
                              <Reply className="w-3 h-3" />
                              {dt('tickets.discussion.reply')}
                            </button>
                          </div>
                        </div>
                      )
                    })}

                    {isReplying && (
                      <div className="ml-6 rounded border p-3" style={{ borderColor: 'var(--border)', background: 'var(--surface)' }}>
                        <p className="text-xs font-medium mb-2" style={{ color: 'var(--text)' }}>{dt('tickets.discussion.reply_to')} {replyingToLog?.authorName ?? replyingToLog?.authorType ?? dt('tickets.discussion.reply_to_fallback')}</p>
                        <textarea
                          className="input w-full min-h-[80px] resize-y text-sm"
                          placeholder={dt('tickets.discussion.reply_placeholder')}
                          value={commentText}
                          onChange={(e) => setCommentText(e.target.value)}
                          autoFocus
                        />
                        <div className="flex items-center gap-2 mt-2">
                          <button
                            type="button"
                            className="inline-flex items-center gap-2 rounded px-3 py-1.5 text-sm"
                            style={{ background: 'var(--brand)', color: 'white' }}
                            onClick={submitComment}
                            disabled={commentMutation.isPending || commentText.trim() === ''}
                          >
                            {commentMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                            {dt('tickets.discussion.send')}
                          </button>
                          <button
                            type="button"
                            className="text-xs"
                            style={{ color: 'var(--muted)' }}
                            onClick={() => { setReplyToLogId(null); setCommentText('') }}
                          >
                            {dt('common.action.cancel')}
                          </button>
                        </div>
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
          </div>

          <div className="space-y-5">
            {/* Logs */}
            {entityData.logs && entityData.logs.length > 0 && (
              <div>
                <button
                  className="flex items-center gap-1.5 text-xs font-medium mb-2 w-full text-left"
                  style={{ color: 'var(--muted)' }}
                  onClick={() => setLogsExpanded(!logsExpanded)}
                >
                  <FileText className="w-3.5 h-3.5" />
                  Journal d'exécution ({entityData.logs.length})
                  {logsExpanded ? <ChevronUp className="w-3 h-3 ml-auto" /> : <ChevronDown className="w-3 h-3 ml-auto" />}
                </button>
                {logsExpanded && (
                  <div className="space-y-2 border-l-2 pl-3" style={{ borderColor: 'var(--border)' }}>
                    {entityData.logs.map((log, i) => {
                      const isError = log.action.includes('error') || log.action.includes('failed')
                      return (
                        <div key={i} className="text-xs">
                          <div className="flex items-center gap-1.5">
                            {isError
                              ? <AlertCircle className="w-3 h-3 text-red-500 flex-shrink-0" />
                              : <CheckCircle className="w-3 h-3 text-green-500 flex-shrink-0" />
                            }
                            <span className={`font-medium ${isError ? 'text-red-600' : ''}`} style={isError ? {} : { color: 'var(--text)' }}>
                              {log.action}
                            </span>
                            <span className="ml-auto flex-shrink-0" style={{ color: 'var(--muted)' }}>
                              {new Date(log.createdAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}
                            </span>
                          </div>
                          {log.content && (
                            <pre className="mt-1 text-xs overflow-x-auto p-2 rounded" style={{ background: 'var(--surface2)', color: 'var(--muted)', whiteSpace: 'pre-wrap', wordBreak: 'break-word', maxHeight: '8rem' }}>
                              {log.content.length > 500 ? log.content.slice(0, 500) + '…' : log.content}
                            </pre>
                          )}
                        </div>
                      )
                    })}
                  </div>
                )}
              </div>
            )}

            {/* Token usage */}
            {entityData.tokenUsage && entityData.tokenUsage.length > 0 && (
              <div>
                <p className="text-xs font-medium mb-2" style={{ color: 'var(--muted)' }}>Tokens consommés</p>
                <div className="list-token-usage space-y-1.5">
                  {(entityData.tokenUsage as TokenUsageEntry[]).map((u) => (
                    <div key={u.id} className="item-token-usage flex items-center gap-3 text-xs px-3 py-2 rounded" style={{ background: 'var(--surface2)' }}>
                      <span className="truncate" style={{ color: 'var(--muted)' }}>{u.model}</span>
                      <span className="ml-auto flex-shrink-0 font-medium" style={{ color: 'var(--brand)' }}>
                        {u.totalTokens.toLocaleString()} tok
                      </span>
                      {u.durationMs !== null && (
                        <span className="flex-shrink-0" style={{ color: 'var(--muted)' }}>
                          {(u.durationMs / 1000).toFixed(1)}s
                        </span>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="fixed inset-0 z-40 flex justify-end" onClick={onClose}>
      <div
        className="w-full max-w-5xl h-full flex flex-col shadow-2xl overflow-hidden"
        style={{ background: 'var(--surface)' }}
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b flex-shrink-0" style={{ borderColor: 'var(--border)' }}>
          <div className="min-w-0">
            <h2 className="text-sm font-semibold truncate" style={{ color: 'var(--text)' }}>
              {isLoading ? 'Loading…' : (entity?.title ?? '—')}
            </h2>
          </div>
          <button onClick={onClose} className="p-1 ml-2 flex-shrink-0" style={{ color: 'var(--muted)' }}>
            <X className="w-4 h-4" />
          </button>
        </div>

        {isLoading && <div className="p-6"><Loader2 className="w-5 h-5 animate-spin mx-auto" style={{ color: 'var(--muted)' }} /></div>}

        {entity && renderEntityContent(entity)}
      </div>

      {linkedTaskId && (
        <TaskDrawer
          taskId={linkedTaskId}
          onClose={() => setLinkedTaskId(null)}
          projectHasTeam={projectHasTeam}
        />
      )}
    </div>
  )
}
