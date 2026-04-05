/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle,
  CheckCircle,
  ChevronDown,
  ChevronRight,
  ChevronUp,
  Clock,
  Loader2,
  Maximize2,
  Minimize2,
  Play,
  RotateCcw,
  X,
  XCircle,
} from 'lucide-react'
import { ticketsApi, ticketTasksApi } from '@/api/tickets'
import { useTranslation } from '@/hooks/useTranslation'
import type {
  Ticket,
  TicketTask,
  TaskStatus,
  TokenUsageEntry,
} from '@/types'
import EntityId from '@/components/ui/EntityId'
import Markdown from '@/components/ui/Markdown'
import TaskActivityFeed from '@/components/project/TaskActivityFeed'
import {
  STATUS_LABEL_KEYS,
  isTicket,
} from '@/lib/project/constants'
import {
  TASK_ACTIVITY_FEED_DOMAIN,
  buildActivityActionLabelKey,
} from '@/lib/project/taskActivityFeed'
import {
  CATALOG_DOMAIN,
  CATALOG_TRANSLATION_KEYS,
  type CatalogTranslationKey,
} from '@/lib/catalog'

const TASK_DRAWER_TRANSLATION_KEYS = [
  'common.action.close',
  'common.action.open',
  'ticket.detail.collapse',
  'ticket.detail.description_label',
  'ticket.detail.expand',
  'ticket.detail.loading',
  'ticket.detail.metric.tokens_consumed',
  'ticket.detail.resume.button',
  'ticket.detail.resume.error',
  'ticket.detail.resume.loading',
  'ticket.detail.resume.manual_help',
  'ticket.detail.resume.title',
  'ticket.detail.subtasks_title',
  'ticket.detail.action_label',
  'ticket.detail.agent_label',
  'ticket.detail.latest_run_label',
  'ticket.detail.status_label',
  'ticket.detail.step_label',
  'ticket.detail.title',
  'ticket.detail.tasks_empty',
  'ticket.detail.tasks_title',
  'ticket.detail.workflow.current_step',
  'ticket.detail.workflow.no_transition',
  'ticket.detail.workflow.none',
  'ticket.detail.workflow.section_title',
  'ticket.detail.workflow.transition_loading',
  'ticket.detail.workflow.transition_to',
] as const

const TASK_DRAWER_CATALOG_KEYS = [
  ...CATALOG_TRANSLATION_KEYS,
  ...Object.values(STATUS_LABEL_KEYS),
] as const

function StatusIcon({ status }: { status: TaskStatus }) {
  if (status === 'done') return <CheckCircle className="h-4 w-4 text-green-500" />
  if (status === 'cancelled') return <XCircle className="h-4 w-4 text-gray-400" />
  if (status === 'in_progress' || status === 'review') return <Clock className="h-4 w-4 text-blue-500" />
  if (status === 'backlog') return <AlertTriangle className="h-4 w-4 text-gray-300" />
  return <ChevronRight className="h-4 w-4 text-gray-400" />
}

function badgeAppearance(tone: 'primary' | 'accent' | 'neutral' | 'warning') {
  if (tone === 'primary') {
    return {
      background: 'color-mix(in srgb, var(--brand-dim) 44%, var(--surface) 56%)',
      borderColor: 'color-mix(in srgb, var(--brand) 22%, var(--border) 78%)',
      color: 'var(--brand)',
    }
  }

  if (tone === 'accent') {
    return {
      background: 'rgba(59,130,246,0.12)',
      borderColor: 'rgba(59,130,246,0.24)',
      color: '#2563eb',
    }
  }

  if (tone === 'warning') {
    return {
      background: 'rgba(245,158,11,0.14)',
      borderColor: 'rgba(245,158,11,0.28)',
      color: '#b45309',
    }
  }

  return {
    background: 'color-mix(in srgb, var(--surface2) 88%, transparent)',
    borderColor: 'var(--border)',
    color: 'var(--text)',
  }
}

function Badge({
  children,
  tone,
}: {
  children: ReactNode
  tone: 'primary' | 'accent' | 'neutral' | 'warning'
}) {
  const style = badgeAppearance(tone)

  return (
    <span
      className="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-semibold tracking-[0.04em]"
      style={style}
    >
      {children}
    </span>
  )
}

const BLOCK_LABEL_CLASS = 'section-title'

function latestExecution(task: TicketTask) {
  return [...(task.executions ?? [])].sort((left, right) => {
    const leftDate = new Date(left.finishedAt ?? left.startedAt ?? 0).getTime()
    const rightDate = new Date(right.finishedAt ?? right.startedAt ?? 0).getTime()
    return rightDate - leftDate
  })[0] ?? null
}

function taskHasActiveExecution(task: TicketTask): boolean {
  return (task.executions ?? []).some((execution) => ['pending', 'running', 'retrying'].includes(execution.status))
}

function resolveTaskActionLabel(task: TicketTask, tc: (key: CatalogTranslationKey) => string): string {
  const actionLabelKey = buildActivityActionLabelKey(task.agentAction.key)
  return actionLabelKey ? tc(actionLabelKey) : task.agentAction.label
}

function statusLabel(status: TaskStatus, tc: (key: CatalogTranslationKey) => string): string {
  return tc(STATUS_LABEL_KEYS[status] as CatalogTranslationKey)
}

/**
 * Displays the ticket/task drawer with the activity feed as the primary surface.
 */
export default function TaskDrawer({
  taskId,
  onClose,
  projectHasTeam,
  isExpanded,
  onExpandedChange,
}: {
  taskId: string
  onClose: () => void
  projectHasTeam: boolean
  isExpanded: boolean
  onExpandedChange: (expanded: boolean) => void
}) {
  const qc = useQueryClient()
  const [commentText, setCommentText] = useState('')
  const [replyToLogId, setReplyToLogId] = useState<string | null>(null)
  const [linkedTaskId, setLinkedTaskId] = useState<string | null>(null)
  const [taskDispatchError, setTaskDispatchError] = useState<string | null>(null)
  const [expandedTaskIds, setExpandedTaskIds] = useState<string[]>([])
  const [pendingTaskActionId, setPendingTaskActionId] = useState<string | null>(null)
  const backdropMouseDownRef = useRef(false)

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

  const { t, formatDateTime, locale } = useTranslation(
    TASK_DRAWER_TRANSLATION_KEYS,
    TASK_ACTIVITY_FEED_DOMAIN,
  )

  const { t: tc } = useTranslation(
    TASK_DRAWER_CATALOG_KEYS,
    CATALOG_DOMAIN,
  )

  useEffect(() => {
    setCommentText('')
    setReplyToLogId(null)
    setLinkedTaskId(null)
    setTaskDispatchError(null)
    setExpandedTaskIds([])
    setPendingTaskActionId(null)
  }, [taskId])

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
    mutationFn: (ticketEntityId: string) => ticketsApi.advance(ticketEntityId),
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
      setTaskDispatchError(msg ?? t('ticket.detail.resume.error'))
    },
  })

  const taskDispatchMutation = useMutation({
    mutationFn: (task: TicketTask) => {
      const hasExecutions = (task.executions?.length ?? 0) > 0
      return hasExecutions
        ? ticketTasksApi.resume(task.id)
        : ticketTasksApi.execute(task.id, task.assignedAgent?.id)
    },
    onMutate: (task) => {
      setPendingTaskActionId(task.id)
      setTaskDispatchError(null)
    },
    onSuccess: async (_, task) => {
      setPendingTaskActionId(null)
      await qc.invalidateQueries({ queryKey: ['task-detail', taskId] })
      await qc.invalidateQueries({ queryKey: ['task-detail', task.id] })
      await qc.invalidateQueries({ queryKey: ['tickets'] })
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { error?: string } } })?.response?.data?.error
      setPendingTaskActionId(null)
      setTaskDispatchError(msg ?? t('ticket.detail.resume.error'))
    },
  })

  const ticketTasks = useMemo(() => {
    if (!entity || !isTicket(entity)) {
      return []
    }

    return [...(entity.tasks ?? [])].sort((left, right) => {
      const leftStep = left.workflowStep?.name ?? ''
      const rightStep = right.workflowStep?.name ?? ''
      if (leftStep !== rightStep) return leftStep.localeCompare(rightStep, locale)
      return left.title.localeCompare(right.title, locale)
    })
  }, [entity, locale])

  const childItems = useMemo(
    () => (entity && isTicket(entity) ? [] : ((entity?.children ?? []) as TicketTask[])),
    [entity],
  )

  const submitComment = () => {
    if (commentText.trim() === '') return
    commentMutation.mutate()
  }

  const toggleTaskExpansion = (targetTaskId: string) => {
    setExpandedTaskIds((current) => (
      current.includes(targetTaskId)
        ? current.filter((id) => id !== targetTaskId)
        : [...current, targetTaskId]
    ))
  }

  const renderPilotBlock = (ticketEntity: Ticket) => (
    <section className="rounded-2xl border p-4" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
      <div className="section-pilot-transition">
        <div className="space-y-1.5">
          <p className="section-title">
            {t('ticket.detail.workflow.section_title')}
          </p>
          <p className="section-legend">
            {t('ticket.detail.workflow.current_step')}: {ticketEntity.workflowStep?.name ?? t('ticket.detail.workflow.none')}
          </p>
        </div>

        {ticketEntity.workflowStepAllowedTransitions.length > 0 && (
          <button
            type="button"
            className="btn-primary"
            onClick={() => advanceMutation.mutate(ticketEntity.id)}
            disabled={!projectHasTeam || advanceMutation.isPending}
          >
            {advanceMutation.isPending
              ? t('ticket.detail.workflow.transition_loading')
              : t('ticket.detail.workflow.transition_to', { step: ticketEntity.workflowStepAllowedTransitions[0]?.name ?? '' })}
          </button>
        )}
      </div>

      {ticketEntity.workflowStepAllowedTransitions.length === 0 && (
        <p className="mt-3 text-xs" style={{ color: 'var(--text)' }}>
          {t('ticket.detail.workflow.no_transition')}
        </p>
      )}

      <div className="mt-5 border-t pt-5" style={{ borderColor: 'var(--border)' }}>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.08em]" style={{ color: 'var(--muted)' }}>
              {t('ticket.detail.tasks_title')}
            </p>
          </div>
        </div>

        {ticketTasks.length === 0 ? (
          <div className="mt-4 rounded-xl border border-dashed px-4 py-4 text-sm" style={{ borderColor: 'var(--border)', color: 'var(--muted)' }}>
            {t('ticket.detail.tasks_empty')}
          </div>
        ) : (
          <div className="mt-4 space-y-3">
            {ticketTasks.map((task, index) => {
              const expanded = expandedTaskIds.includes(task.id)
              const canDispatch = projectHasTeam && !taskHasActiveExecution(task)
              const latest = latestExecution(task)
              const isPending = pendingTaskActionId === task.id

              return (
                <article key={task.id} className="rounded-xl border" style={{ borderColor: 'var(--border)', background: 'var(--surface)' }}>
                  <div className="flex flex-wrap items-center gap-3 px-4 py-3">
                    <span className="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-[10px] font-bold tabular-nums" style={{ borderColor: 'var(--border)', background: 'var(--surface2)', color: 'var(--muted)' }}>
                      {index + 1}
                    </span>
                    <span className="mt-0.5 shrink-0">
                      <StatusIcon status={task.status} />
                    </span>
                    <button
                      type="button"
                      className="min-w-0 flex-1 text-left"
                      onClick={() => toggleTaskExpansion(task.id)}
                    >
                      <div className="flex min-w-0 flex-wrap items-center gap-2">
                        <span className="truncate text-sm font-semibold" style={{ color: 'var(--text)' }}>
                          {task.title}
                        </span>
                        {task.workflowStep && (
                          <Badge tone="accent">{task.workflowStep.name}</Badge>
                        )}
                      </div>
                    </button>

                    <div className="ml-auto flex items-center gap-1.5">
                      <button
                      type="button"
                      className="inline-flex h-9 w-9 items-center justify-center rounded-lg border"
                      style={{ borderColor: 'var(--border)', background: 'var(--surface2)', color: 'var(--muted)' }}
                      disabled={!canDispatch || isPending}
                      aria-label={t('ticket.detail.resume.button')}
                      title={t('ticket.detail.resume.button')}
                      onClick={() => taskDispatchMutation.mutate(task)}
                    >
                        {isPending ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Play className="h-3.5 w-3.5" />}
                      </button>

                      <button
                        type="button"
                        className="inline-flex h-9 w-9 items-center justify-center rounded-lg border"
                        style={{ borderColor: 'var(--border)', background: 'var(--surface2)', color: 'var(--muted)' }}
                        onClick={() => setLinkedTaskId(task.id)}
                        aria-label={t('common.action.open')}
                        title={t('common.action.open')}
                      >
                        <ChevronRight className="h-3.5 w-3.5" />
                      </button>

                      <button
                        type="button"
                        className="inline-flex h-9 w-9 items-center justify-center rounded-lg border"
                        style={{ borderColor: 'var(--border)', background: 'var(--surface2)', color: 'var(--muted)' }}
                        onClick={() => toggleTaskExpansion(task.id)}
                        aria-label={expanded ? t('ticket.detail.collapse') : t('ticket.detail.expand')}
                        title={expanded ? t('ticket.detail.collapse') : t('ticket.detail.expand')}
                      >
                        {expanded ? <ChevronUp className="h-3.5 w-3.5" /> : <ChevronDown className="h-3.5 w-3.5" />}
                      </button>
                    </div>
                  </div>

                  {expanded && (
                    <div className="space-y-4 border-t px-4 py-4" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
                      {task.description && (
                        <div>
                          <p className="mb-1 text-xs font-semibold uppercase tracking-[0.08em]" style={{ color: 'var(--muted)' }}>
                            {t('ticket.detail.description_label')}
                          </p>
                          <Markdown content={task.description} density="compact" />
                        </div>
                      )}

                      <div className="flex flex-wrap items-center gap-2 text-sm">
                        <Badge tone="neutral">{t('ticket.detail.status_label')}: {statusLabel(task.status, tc)}</Badge>
                        {task.workflowStep && <Badge tone="accent">{t('ticket.detail.step_label')}: {task.workflowStep.name}</Badge>}
                        {task.assignedAgent && <Badge tone="primary">{t('ticket.detail.agent_label')}: {task.assignedAgent.name}</Badge>}
                        <Badge tone="warning">{t('ticket.detail.action_label')}: {resolveTaskActionLabel(task, tc)}</Badge>
                      </div>

                      {task.dependsOn.length > 0 && (
                        <div>
                          <div className="space-y-2">
                            {task.dependsOn.map((dependency) => (
                              <div key={dependency.id} className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm" style={{ borderColor: 'var(--border)', background: 'var(--surface)' }}>
                                <StatusIcon status={dependency.status} />
                                <span style={{ color: 'var(--text)' }}>{dependency.title}</span>
                              </div>
                            ))}
                          </div>
                        </div>
                      )}

                      {latest && (
                        <div className="flex flex-wrap items-center gap-2 rounded-lg border px-3 py-3 text-sm" style={{ borderColor: 'var(--border)', background: 'var(--surface)' }}>
                          <StatusIcon status={task.status} />
                          <span className="text-xs" style={{ color: 'var(--muted)' }}>
                            {t('ticket.detail.latest_run_label')}:{" "}
                            {latest.currentAttempt}/{latest.maxAttempts}
                          </span>
                          <span className="ml-auto text-xs" style={{ color: 'var(--muted)' }}>
                            {formatDateTime(latest.finishedAt ?? latest.startedAt ?? '')}
                          </span>
                        </div>
                      )}
                    </div>
                  )}
                </article>
              )
            })}
          </div>
        )}
      </div>

      {taskDispatchError && (
        <p className="mt-4 text-sm" style={{ color: '#b91c1c' }}>
          {taskDispatchError}
        </p>
      )}
    </section>
  )

  const renderTaskControls = (taskEntity: TicketTask) => (
    <section className="space-y-4">
      <div className="rounded-2xl border p-4" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
        <p className="text-sm font-semibold" style={{ color: 'var(--text)' }}>
          {t('ticket.detail.resume.title')}
        </p>
        <p className="mt-1 text-sm" style={{ color: 'var(--muted)' }}>
          {t('ticket.detail.resume.manual_help')}
        </p>
        <button
          type="button"
          className="mt-4 inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium"
          style={{ background: 'var(--brand-dim)', color: 'var(--brand)' }}
          onClick={() => taskResumeMutation.mutate(taskEntity.id)}
          disabled={!projectHasTeam || taskHasActiveExecution(taskEntity) || taskResumeMutation.isPending}
          aria-label={t('ticket.detail.resume.button')}
        >
          {taskResumeMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <RotateCcw className="h-4 w-4" />}
          {taskResumeMutation.isPending ? t('ticket.detail.resume.loading') : t('ticket.detail.resume.button')}
        </button>
        {taskDispatchError && (
          <p className="mt-3 text-sm" style={{ color: '#b91c1c' }}>
            {taskDispatchError}
          </p>
        )}
      </div>

      {childItems.length > 0 && (
        <div className="rounded-2xl border p-4" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
          <p className="text-sm font-semibold" style={{ color: 'var(--text)' }}>
            {t('ticket.detail.subtasks_title')}
          </p>
          <div className="mt-4 space-y-2">
            {childItems.map((child) => (
              <div key={child.id} className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm" style={{ borderColor: 'var(--border)', background: 'var(--surface)' }}>
                <StatusIcon status={child.status} />
                <span className="truncate" style={{ color: 'var(--text)' }}>{child.title}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </section>
  )

  const renderBody = (entityData: Ticket | TicketTask) => (
    <div className="flex-1 overflow-y-auto">
      <div className="space-y-5 px-5 py-5">
        <div className="space-y-3">
          <h2 className="text-xl font-semibold tracking-tight" style={{ color: 'var(--text)' }}>
            {isLoading ? t('ticket.detail.loading') : (entityData.title ?? '—')}
          </h2>

          <div className="flex flex-wrap gap-2">
            <Badge tone="neutral">{statusLabel(entityData.status, tc)}</Badge>
            {isTicket(entityData) && entityData.workflowStep && (
              <Badge tone="accent">{entityData.workflowStep.name}</Badge>
            )}
            {entityData.branchName && (
              <Badge tone="primary">
                {entityData.branchUrl ? (
                  <a href={entityData.branchUrl} target="_blank" rel="noreferrer" className="hover:underline" style={{ color: 'inherit' }}>
                    <code className="font-mono">{entityData.branchName}</code>
                  </a>
                ) : (
                  <code className="font-mono">{entityData.branchName}</code>
                )}
              </Badge>
            )}
          </div>

          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs" style={{ color: 'var(--muted)' }}>
            <EntityId id={entityData.id} />
            <span className="inline-flex items-center gap-1">
              <Clock className="h-3.5 w-3.5" />
              {formatDateTime(entityData.createdAt)}
            </span>
          </div>

        {entityData.description && (
          <section className="rounded-2xl border p-4" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
            <p className={BLOCK_LABEL_CLASS + ' mb-2'}>
              {t('ticket.detail.description_label')}
            </p>
            <Markdown content={entityData.description} density="compact" preserveLineBreaks />
          </section>
        )}
        </div>

        {isTicket(entityData) ? renderPilotBlock(entityData) : renderTaskControls(entityData)}

        {entityData && (
          <TaskActivityFeed
            logs={entityData.logs ?? []}
            executions={entityData.executions ?? []}
            commentText={commentText}
            setCommentText={setCommentText}
            replyToLogId={replyToLogId}
            setReplyToLogId={setReplyToLogId}
            onSubmitComment={submitComment}
            commentMutationPending={commentMutation.isPending}
          />
        )}

        {entityData.tokenUsage && entityData.tokenUsage.length > 0 && (
          <section className="rounded-2xl border p-4" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
            <div className="flex flex-wrap items-end justify-between gap-3">
              <div>
                <p className="text-sm font-semibold" style={{ color: 'var(--text)' }}>
                  {t('ticket.detail.metric.tokens_consumed')}
                </p>
              </div>
            </div>

            <div className="mt-4 space-y-2">
              {(entityData.tokenUsage as TokenUsageEntry[]).map((usage) => (
                <div key={usage.id} className="flex flex-wrap items-center gap-3 rounded-lg border px-3 py-2 text-sm" style={{ borderColor: 'var(--border)', background: 'var(--surface)' }}>
                  <span style={{ color: 'var(--muted)' }}>{usage.model}</span>
                  <span className="ml-auto font-medium" style={{ color: 'var(--brand)' }}>
                    {usage.totalTokens.toLocaleString()} tok
                  </span>
                  {usage.durationMs !== null && (
                    <span className="text-xs" style={{ color: 'var(--muted)' }}>
                      {(usage.durationMs / 1000).toFixed(1)}s
                    </span>
                  )}
                </div>
              ))}
            </div>
          </section>
        )}
      </div>
    </div>
  )

  return (
    <div
      className="fixed inset-y-0 right-0 z-40 flex justify-end"
      style={{ left: '16rem' }}
      onMouseDown={(e) => { backdropMouseDownRef.current = e.target === e.currentTarget }}
      onClick={() => { if (backdropMouseDownRef.current) onClose() }}
    >
      <div
        className={`h-full flex flex-col overflow-hidden shadow-2xl transition-[max-width] duration-200 ease-out ${isExpanded ? 'w-full max-w-none' : 'w-full max-w-6xl'}`}
        style={{ background: 'var(--surface)' }}
        onClick={(event) => event.stopPropagation()}
      >
      <div className="flex items-center justify-between border-b px-5 py-4" style={{ borderColor: 'var(--border)' }}>
        <div className="ml-2 min-w-0">
          <span className="truncate text-sm font-semibold" style={{ color: 'var(--text)' }}>
            {t('ticket.detail.title')}
          </span>
        </div>
        <div className="ml-4 flex flex-shrink-0 items-center gap-2">
          <button
            type="button"
            className="inline-flex items-center gap-1.5 rounded border px-3 py-1.5 text-xs font-medium"
            style={{ background: 'var(--surface2)', color: 'var(--muted)' }}
            onClick={() => onExpandedChange(!isExpanded)}
            aria-label={isExpanded ? t('ticket.detail.collapse') : t('ticket.detail.expand')}
            title={isExpanded ? t('ticket.detail.collapse') : t('ticket.detail.expand')}
          >
            {isExpanded ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}
            <span>{isExpanded ? t('ticket.detail.collapse') : t('ticket.detail.expand')}</span>
          </button>
          <button
            onClick={onClose}
            className="flex-shrink-0 p-1"
            style={{ color: 'var(--muted)' }}
            aria-label={t('common.action.close')}
            title={t('common.action.close')}
          >
            <X className="h-4 w-4" />
          </button>
        </div>
        </div>

        {isLoading && (
          <div className="p-6">
            <Loader2 className="mx-auto h-5 w-5 animate-spin" style={{ color: 'var(--muted)' }} />
          </div>
        )}

        {entity && renderBody(entity)}
      </div>

      {linkedTaskId && (
        <TaskDrawer
          taskId={linkedTaskId}
          onClose={() => setLinkedTaskId(null)}
          projectHasTeam={projectHasTeam}
          isExpanded={isExpanded}
          onExpandedChange={onExpandedChange}
        />
      )}
    </div>
  )
}
