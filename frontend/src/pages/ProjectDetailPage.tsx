/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useEffect, useState, useMemo, type ComponentType } from 'react'
import { useParams, Link, useSearchParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft, Plus, Code2, Globe, XCircle, CheckCircle, Clock,
  AlertTriangle, ChevronRight, GitBranch, User, ArrowRight,
  Layers, ListTodo, Users, Settings, Kanban, Zap, Send, RotateCcw,
  Loader2, AlertCircle, History, Coins, X, FileText,
  ChevronDown, ChevronUp, Reply,
} from 'lucide-react'
import { projectsApi } from '@/api/projects'
import { workflowsApi } from '@/api/workflows'
import { translationsApi } from '@/api/translations'
import { ticketsApi, ticketTasksApi, type ProjectRequestPayload, type ProjectRequestResult, type TicketTaskPayload } from '@/api/tickets'
import { teamsApi } from '@/api/teams'
import { agentsApi } from '@/api/agents'
import type {
  Ticket,
  TicketTask,
  TicketLog,
  AgentTaskExecution,
  TaskStatus,
  TaskPriority,
  TaskType,
  Workflow,
  Module,
  AgentSummary,
  TokenUsageEntry,
} from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'
import EntityId from '@/components/ui/EntityId'
import AgentSheet from '@/components/project/AgentSheet'
import Markdown from '@/components/ui/Markdown'

// ─── Constants ────────────────────────────────────────────────────────────────

const TYPE_BADGE:  Record<TaskType, string>    = { user_story: 'badge-blue', bug: 'badge-red', task: 'badge-green' }
const TYPE_LABELS: Record<TaskType, string>    = { user_story: 'US', bug: 'Bug', task: 'Tâche' }
const PRIORITY_LABELS: Record<TaskPriority, string> = { low: 'Faible', medium: 'Normale', high: 'Haute', critical: 'Critique' }
const PRIORITY_COLOR:  Record<TaskPriority, string> = { low: 'text-gray-400', medium: 'text-blue-500', high: 'text-orange-500', critical: 'text-red-600' }
const STATUS_LABELS: Record<TaskStatus, string> = {
  backlog: 'Backlog', todo: 'À faire', in_progress: 'En cours',
  review: 'Revue', done: 'Terminé', cancelled: 'Annulé',
}

const AUDIT_ACTION_LABELS: Record<string, string> = {
  'project.created': 'Projet créé', 'project.updated': 'Projet modifié', 'project.deleted': 'Projet supprimé',
  'task.created': 'Tâche créée', 'task.updated': 'Tâche modifiée', 'task.deleted': 'Tâche supprimée',
  'task.assigned': 'Tâche assignée', 'task.status_changed': 'Statut changé', 'task.progress_updated': 'Progression mise à jour',
  'task.validation_asked': 'Validation demandée', 'task.validated': 'Tâche validée', 'task.rejected': 'Tâche rejetée',
  'task.reprioritized': 'Priorité modifiée',
}

const EXECUTION_STATUS_LABELS: Record<AgentTaskExecution['status'], string> = {
  pending: 'En attente',
  running: 'En cours',
  retrying: 'En retry',
  succeeded: 'Réussie',
  failed: 'Échouée',
  dead_letter: 'Dead letter',
  cancelled: 'Annulée',
}

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

// ─── Helpers ──────────────────────────────────────────────────────────────────

function ProgressBar({ value }: { value: number }) {
  return (
    <div className="w-full bg-gray-100 rounded-full h-1">
      <div className="h-1 rounded-full" style={{ width: `${value}%`, background: 'var(--brand)' }} />
    </div>
  )
}

function StatusIcon({ status }: { status: TaskStatus }) {
  if (status === 'done')       return <CheckCircle className="w-4 h-4 text-green-500" />
  if (status === 'cancelled')  return <XCircle className="w-4 h-4 text-gray-400" />
  if (status === 'in_progress' || status === 'review') return <Clock className="w-4 h-4 text-blue-500" />
  if (status === 'backlog')    return <AlertTriangle className="w-4 h-4 text-gray-300" />
  return <ChevronRight className="w-4 h-4 text-gray-400" />
}

function formatDateTime(value: string, locale?: string) {
  return new Intl.DateTimeFormat(locale, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
}

function formatTime(value: string, locale?: string) {
  return new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(new Date(value))
}

/**
 * Returns the subtasks that belong to the story's currently active workflow column.
 */
function isTicket(entity: Ticket | TicketTask): entity is Ticket {
  return 'projectId' in entity
}

// ─── Story card (Kanban) ──────────────────────────────────────────────────────

/**
 * Kanban card for a user story or bug.
 * Shows type badge, priority, branch name, assigned role, active-column subtasks,
 * and allowed story transitions as buttons.
 */
function StoryCard({ ticket, onTransition, onDelete, onOpen, transitioning, progressBlockedReason }: {
  ticket: Ticket
  onTransition: (ticket: Ticket) => void
  onDelete: (ticket: Ticket) => void
  onOpen: (ticket: Ticket) => void
  transitioning: boolean
  progressBlockedReason: string | null
}) {
  const progressionBlocked = progressBlockedReason !== null
  const activeSubtasks = ticket.activeStepTasks ?? []

  return (
    <div className="card p-3 space-y-2 text-sm">
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-1.5 flex-wrap">
          <span className={`${TYPE_BADGE[ticket.type]} text-xs`}>{TYPE_LABELS[ticket.type]}</span>
          <span className={`text-xs font-medium ${PRIORITY_COLOR[ticket.priority]}`}>{PRIORITY_LABELS[ticket.priority]}</span>
        </div>
        <button onClick={() => onDelete(ticket)} className="p-0.5 text-gray-300 hover:text-red-400 flex-shrink-0" title="Supprimer">
          <XCircle className="w-3.5 h-3.5" />
        </button>
      </div>

      <button
        className="text-left font-medium leading-snug hover:underline w-full"
        style={{ color: 'var(--text)' }}
        onClick={() => onOpen(ticket)}
      >{ticket.title}</button>

      {ticket.branchName && (
        <div className="flex items-center gap-1 text-xs" style={{ color: 'var(--muted)' }}>
          <GitBranch className="w-3 h-3" />
          {ticket.branchUrl ? (
            <a href={ticket.branchUrl} target="_blank" rel="noreferrer" className="truncate hover:underline" style={{ color: 'var(--brand)' }}>
              <code className="font-mono">{ticket.branchName}</code>
            </a>
          ) : (
            <code className="font-mono truncate">{ticket.branchName}</code>
          )}
        </div>
      )}
      {ticket.assignedRole && (
        <div className="flex items-center gap-1 text-xs" style={{ color: 'var(--muted)' }}>
          <User className="w-3 h-3" /><span>{ticket.assignedRole.name}</span>
        </div>
      )}
      {activeSubtasks.length > 0 && (
        <div className="space-y-1 rounded border px-2 py-2" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
          <p className="text-[11px] font-medium uppercase tracking-wide" style={{ color: 'var(--muted)' }}>
            Sous-tâches actives
          </p>
          <div className="space-y-1">
            {activeSubtasks.map((subtask) => (
              <div key={subtask.id} className="flex items-center gap-2 text-xs">
                <StatusIcon status={subtask.status} />
                <span className="truncate" style={{ color: 'var(--text)' }}>{subtask.title}</span>
              </div>
            ))}
          </div>
        </div>
      )}
      {ticket.workflowStepAllowedTransitions.length > 0 && (
        <div className="flex flex-wrap gap-1 pt-1 border-t" style={{ borderColor: 'var(--border)' }}>
          {ticket.workflowStepAllowedTransitions.map((next) => (
            <button
              key={next.id}
              onClick={() => onTransition(ticket)}
              disabled={transitioning || progressionBlocked}
              className="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded border hover:border-[var(--brand)] hover:text-[var(--brand)] transition-colors disabled:opacity-40 disabled:cursor-wait"
              style={{ borderColor: 'var(--border)', color: 'var(--muted)' }}
              title={progressBlockedReason ?? undefined}
            >
              <ArrowRight className="w-2.5 h-2.5" />
              {transitioning ? '…' : next.name}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

// ─── Story board (Kanban) ─────────────────────────────────────────────────────

/**
 * Kanban board with one column per workflow step.
 * The `pendingTaskId` disables transition buttons on the card being updated to prevent double-clicks.
 */
function StoryBoard({ tickets, steps, onTransition, onDelete, onOpen, pendingTaskId, progressBlockedReason }: {
  tickets: Ticket[]
  steps: Array<{ id: string; key: string; name: string }>
  onTransition: (ticket: Ticket) => void
  onDelete: (ticket: Ticket) => void
  onOpen: (ticket: Ticket) => void
  pendingTaskId: string | null
  progressBlockedReason: string | null
}) {
  if (tickets.length === 0) {
    return <EmptyState icon={Layers} title="Aucune story ni bug" description="Créez une demande via le bouton ci-dessus pour l'envoyer au Product Owner." />
  }
  return (
    <div className="flex gap-3 overflow-x-auto pb-4">
      {steps.map((col, index) => {
        const accent = ['#94a3b8', '#3b82f6', '#8b5cf6', '#ec4899', '#f97316', '#eab308', '#22c55e'][index % 7]
        const cards = tickets.filter((s) => s.workflowStep?.key === col.key)
        return (
          <div key={col.key} className="flex-shrink-0 w-60">
            <div
              className="flex items-center justify-between rounded-t-lg border border-b-0 px-3 py-1.5"
              style={{
                background: `color-mix(in srgb, ${accent} 14%, var(--surface2))`,
                borderColor: `color-mix(in srgb, ${accent} 32%, var(--border))`,
              }}
            >
              <span className="text-xs font-semibold" style={{ color: accent }}>{col.name}</span>
              {cards.length > 0 && <span className="text-xs font-medium" style={{ color: accent, opacity: 0.72 }}>{cards.length}</span>}
            </div>
            <div className="space-y-2 min-h-16 rounded-b-lg p-2 border border-t-0" style={{ background: 'var(--surface2)', borderColor: 'var(--border)' }}>
              {cards.map((ticket) => (
                <StoryCard
                  key={ticket.id}
                  ticket={ticket}
                  onTransition={onTransition}
                  onDelete={onDelete}
                  onOpen={onOpen}
                  transitioning={pendingTaskId === ticket.id}
                  progressBlockedReason={progressBlockedReason}
                />
              ))}
              {cards.length === 0 && <p className="text-xs text-center py-3" style={{ color: 'var(--muted)' }}>—</p>}
            </div>
          </div>
        )
      })}
    </div>
  )
}

// ─── Task row (technical tasks) ───────────────────────────────────────────────

/**
 * Single row for a technical task in the Tâches tab.
 * Shows status icon, type badge, title, priority, assigned agent/role, parent story title, and progress bar.
 */
function TechTaskRow({
  task,
  parent,
  onDelete,
  onOpen,
}: {
  task: TicketTask
  parent: TicketTask | Ticket | null
  onDelete: (t: TicketTask) => void
  onOpen: (t: TicketTask) => void
}) {
  return (
    <div className="px-4 py-3 flex items-center gap-3">
      <StatusIcon status={task.status} />
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className={`${TYPE_BADGE.task} text-xs`}>{TYPE_LABELS.task}</span>
          <button className="text-sm font-medium truncate hover:underline text-left" style={{ color: 'var(--text)' }} onClick={() => onOpen(task)}>{task.title}</button>
        </div>
        <div className="flex items-center gap-3 mt-0.5 flex-wrap">
          <span className="text-xs" style={{ color: 'var(--muted)' }}>{STATUS_LABELS[task.status]}</span>
          <span className={`text-xs font-medium ${PRIORITY_COLOR[task.priority]}`}>{PRIORITY_LABELS[task.priority]}</span>
          {task.assignedAgent && <span className="text-xs" style={{ color: 'var(--muted)' }}>→ {task.assignedAgent.name}</span>}
          {task.assignedRole  && <span className="text-xs italic" style={{ color: 'var(--muted)' }}>({task.assignedRole.name})</span>}
          {parent && <span className="text-xs opacity-50" style={{ color: 'var(--muted)' }}>↑ {parent.title}</span>}
        </div>
        {task.progress > 0 && (
          <div className="mt-1.5 flex items-center gap-2">
            <ProgressBar value={task.progress} />
            <span className="text-xs whitespace-nowrap" style={{ color: 'var(--muted)' }}>{task.progress}%</span>
          </div>
        )}
      </div>
      <button onClick={() => onDelete(task)} className="p-1.5 flex-shrink-0" style={{ color: 'var(--muted)' }} title="Supprimer">
        <XCircle className="w-3.5 h-3.5" />
      </button>
    </div>
  )
}

// ─── Agent status badge ───────────────────────────────────────────────────────

/**
 * Fetches and displays the runtime status of a single agent.
 * Auto-refreshes every 30 seconds.
 */
function AgentStatusBadge({ agentId }: { agentId: string }) {
  const { data, isLoading } = useQuery({
    queryKey: ['agent-status', agentId],
    queryFn:  () => agentsApi.getStatus(agentId),
    refetchInterval: 30_000,
  })

  if (isLoading) return <Loader2 className="w-3.5 h-3.5 animate-spin" style={{ color: 'var(--muted)' }} />

  const status = data?.status ?? 'idle'
  if (status === 'working') return <span className="badge-orange text-xs">En travail</span>
  if (status === 'error')   return <span className="badge-red text-xs">Erreur</span>
  return <span className="badge-green text-xs">Disponible</span>
}

// ─── Task creation form ───────────────────────────────────────────────────────

function TaskForm({ initial, onSubmit, loading, onCancel, tickets, actions }: {
  initial?: Partial<TicketTaskPayload & { ticketId?: string; type?: TaskType }>
  onSubmit: (d: TicketTaskPayload & { ticketId?: string; type?: TaskType }) => void
  loading: boolean
  onCancel: () => void
  tickets?: Array<{ id: string; title: string }>
  actions?: Array<{ key: string; label: string }>
}) {
  const [title, setTitle]             = useState(initial?.title ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [type, setType]               = useState<TaskType>(initial?.type ?? 'user_story')
  const [priority, setPriority]       = useState<TaskPriority>(initial?.priority ?? 'medium')
  const [ticketId, setTicketId]       = useState(initial?.ticketId ?? '')
  const [actionKey, setActionKey]     = useState(initial?.actionKey ?? '')

  return (
    <form onSubmit={(e) => {
      e.preventDefault()
      onSubmit({
        title,
        description: description || undefined,
        type,
        priority,
        ticketId: type === 'task' ? ticketId || undefined : undefined,
        actionKey: type === 'task' ? actionKey || undefined : undefined,
      })
    }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Titre *</label>
        <input className="input" value={title} onChange={(e) => setTitle(e.target.value)} required placeholder="En tant qu'utilisateur, je veux..." />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Type</label>
          <select className="input" value={type} onChange={(e) => setType(e.target.value as TaskType)}>
            <option value="user_story">User Story</option>
            <option value="bug">Bug</option>
            <option value="task">Tâche technique</option>
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Priorité</label>
          <select className="input" value={priority} onChange={(e) => setPriority(e.target.value as TaskPriority)}>
            <option value="low">Faible</option>
            <option value="medium">Normale</option>
            <option value="high">Haute</option>
            <option value="critical">Critique</option>
          </select>
        </div>
      </div>
      {type === 'task' && (
        <>
          <div>
            <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Ticket parent</label>
            <select className="input" value={ticketId} onChange={(e) => setTicketId(e.target.value)} required>
              <option value="">— Sélectionner un ticket —</option>
              {tickets?.map((ticket) => (
                <option key={ticket.id} value={ticket.id}>{ticket.title}</option>
              ))}
            </select>
            {(tickets?.length ?? 0) === 0 && (
              <p className="mt-1 text-xs" style={{ color: '#dc2626' }}>Crée d'abord un ticket avant d'ajouter une tâche technique.</p>
            )}
          </div>
          <div>
            <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Action *</label>
            <select className="input" value={actionKey} onChange={(e) => setActionKey(e.target.value)} required>
              <option value="">— Sélectionner une action —</option>
              {actions?.map((action) => (
                <option key={action.key} value={action.key}>{action.label}</option>
              ))}
            </select>
            {(actions?.length ?? 0) === 0 && (
              <p className="mt-1 text-xs" style={{ color: '#dc2626' }}>Aucune action n’est disponible pour ce workflow.</p>
            )}
          </div>
        </>
      )}
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Description</label>
        <textarea className="input resize-none" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Annuler</button>
        <button type="submit" className="btn-primary" disabled={loading || (type === 'task' && (!ticketId || !actionKey))}>{loading ? 'Enregistrement…' : 'Enregistrer'}</button>
      </div>
    </form>
  )
}

function RequestForm({ onSubmit, loading, onCancel }: {
  onSubmit: (d: ProjectRequestPayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const [title, setTitle]             = useState('')
  const [description, setDescription] = useState('')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ title, description: description || undefined }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Demande *</label>
        <input
          className="input"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          required
          placeholder="Ex: permettre l'export PDF des rapports"
        />
      </div>
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Contexte</label>
        <textarea
          className="input resize-none"
          rows={4}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          placeholder="Décrivez le besoin métier, le problème, les contraintes ou le résultat attendu."
        />
      </div>
      <p className="text-xs" style={{ color: 'var(--muted)' }}>
        Cette demande crée une user story et la transmet automatiquement à un agent Product Owner si le workflow ou l'équipe le permet.
      </p>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Annuler</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? 'Transmission…' : 'Envoyer au PO'}</button>
      </div>
    </form>
  )
}

// ─── Task detail drawer ───────────────────────────────────────────────────────

/**
 * Right-side drawer showing the full detail of a task: description, workflow step,
 * execution logs, subtasks (children), and token consumption.
 * Fetches the explicit ticket or ticket-task endpoint, then shows logs, executions and token usage.
 */
function TaskDrawer({ taskId, onClose, projectHasTeam }: { taskId: string; onClose: () => void; projectHasTeam: boolean }) {
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

        {entity && (() => {
          const logs = entity.logs ?? []
          const executions = entity.executions ?? []
          const childItems = isTicket(entity) ? (entity.tasks ?? []) : (entity.children ?? [])
          const ticketTasks = isTicket(entity)
            ? [...(entity.tasks ?? [])].sort((left, right) => {
                const leftStep = left.workflowStep?.name ?? ''
                const rightStep = right.workflowStep?.name ?? ''
                if (leftStep !== rightStep) return leftStep.localeCompare(rightStep, 'fr')
                return left.title.localeCompare(right.title, 'fr')
              })
            : []
          const commentLogs = logs.filter((log) => log.kind === 'comment')
          
          /**
           * Represents a comment thread rooted at a parent-less log entry.
           */
          interface CommentThread {
            /** Root message of the thread (no parent). */
            root: TicketLog
            /** Direct replies to the root message (flat list — no nested replies). */
            replies: TicketLog[]
          }

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
          
          const totalTokens = (entity.tokenUsage ?? []).reduce((sum, entry) => sum + entry.totalTokens, 0)
          const totalCalls = entity.tokenUsage?.length ?? 0
          const pendingQuestions = commentLogs.filter((log) => log.requiresAnswer).length
          const latestExecutionErrorIndex = [...logs].map((log, index) => ({ log, index })).reverse().find(({ log }) => log.action === 'execution_error')?.index ?? -1
          const latestExecutionError = latestExecutionErrorIndex >= 0 ? logs[latestExecutionErrorIndex] : null
          const hasSuccessAfterLatestError = latestExecutionErrorIndex >= 0
            ? logs.slice(latestExecutionErrorIndex + 1).some((log) => {
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
          <div className="flex-1 min-h-0 overflow-y-auto p-5 space-y-5">
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
              <span className={`${TYPE_BADGE[isTicket(entity) ? entity.type : 'task']} text-xs`}>{TYPE_LABELS[isTicket(entity) ? entity.type : 'task']}</span>
              <span className={`text-xs font-medium ${PRIORITY_COLOR[entity.priority]}`}>{PRIORITY_LABELS[entity.priority]}</span>
              {isTicket(entity) && entity.workflowStep && (
                <span className="badge-blue text-xs">{entity.workflowStep.name}</span>
              )}
              <span className="text-xs" style={{ color: 'var(--muted)' }}>{STATUS_LABELS[entity.status]}</span>
            </div>

            <div>
              <EntityId id={entity.id} />
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
                {isTicket(entity) ? (
                  <p className="mt-2 text-xs" style={{ color: 'var(--muted)' }}>
                    La relance manuelle se fait désormais au niveau des tâches.
                  </p>
                ) : (
                  <>
                    <button
                      type="button"
                      className="mt-2 inline-flex items-center gap-2 rounded px-3 py-2 text-sm"
                      style={{ background: 'var(--brand-dim)', color: 'var(--brand)' }}
                      onClick={() => taskResumeMutation.mutate(entity.id)}
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

            {isTicket(entity) && (
              <div className="rounded border p-4" style={{ borderColor: 'var(--border)', background: 'color-mix(in srgb, var(--surface2) 82%, transparent)' }}>
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <p className="text-sm font-medium" style={{ color: 'var(--text)' }}>Progression du ticket</p>
                    <p className="text-xs" style={{ color: 'var(--muted)' }}>
                      Étape courante : {entity.workflowStep?.name ?? 'Aucune'}
                    </p>
                  </div>
                  {entity.workflowStepAllowedTransitions.length > 0 && (
                    <button
                      type="button"
                      className="btn-primary"
                      onClick={() => advanceMutation.mutate(entity.id)}
                      disabled={!projectHasTeam || advanceMutation.isPending}
                      title={!projectHasTeam ? tt('projects.progress.ui.blocked_reason') : undefined}
                    >
                      {advanceMutation.isPending ? 'Transition…' : `Passer à ${entity.workflowStepAllowedTransitions[0]?.name ?? 'la suite'}`}
                    </button>
                  )}
                </div>
                {entity.workflowStepAllowedTransitions.length === 0 && (
                  <p className="mt-3 text-xs" style={{ color: 'var(--muted)' }}>
                    Aucun passage manuel disponible depuis cette étape.
                  </p>
                )}
              </div>
            )}

            {/* Description */}
            {entity.description && (
              <div>
                <p className="text-xs font-medium mb-1" style={{ color: 'var(--muted)' }}>Description</p>
                <div className="rounded border p-4" style={{ borderColor: 'var(--border)', background: 'color-mix(in srgb, var(--surface2) 82%, transparent)' }}>
                  <Markdown content={entity.description} className="text-sm" />
                </div>
              </div>
            )}

            {/* Branch */}
            {entity.branchName && (
              <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--muted)' }}>
                <GitBranch className="w-3.5 h-3.5 flex-shrink-0" />
                {entity.branchUrl ? (
                  <a href={entity.branchUrl} target="_blank" rel="noreferrer" className="hover:underline" style={{ color: 'var(--brand)' }}>
                    <code className="font-mono">{entity.branchName}</code>
                  </a>
                ) : (
                  <code className="font-mono">{entity.branchName}</code>
                )}
              </div>
            )}

            {isTicket(entity) && (
              <div>
                <p className="text-xs font-medium mb-2" style={{ color: 'var(--muted)' }}>Tâches du ticket ({ticketTasks.length})</p>
                {ticketTasks.length === 0 ? (
                  <div className="rounded border border-dashed px-4 py-4 text-sm" style={{ borderColor: 'var(--border)', color: 'var(--muted)' }}>
                    Aucune tâche liée à ce ticket pour l’instant.
                  </div>
                ) : (
                  <div className="space-y-2">
                    {ticketTasks.map((task) => (
                      <div
                        key={task.id}
                        className="w-full rounded border px-3 py-3 text-left"
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
            {!isTicket(entity) && childItems.length > 0 && (
              <div>
                <p className="text-xs font-medium mb-2" style={{ color: 'var(--muted)' }}>Sous-tâches ({childItems.length})</p>
                <div className="space-y-1">
                  {childItems.map((c) => (
                    <div key={c.id} className="flex items-center gap-2 text-xs px-3 py-2 rounded" style={{ background: 'var(--surface2)' }}>
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
                <div className="space-y-2 max-h-96 overflow-y-auto pr-1">
                  {executions.map((execution) => (
                    <div key={execution.id} className="rounded border p-4" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
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
                      <div key={root.id} className="space-y-2">
                        <div className="rounded border p-4" style={{ borderColor: 'var(--border)', background: isAgent ? 'color-mix(in srgb, var(--brand-dim) 34%, var(--surface) 66%)' : 'var(--surface2)' }}>
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
                            <div key={reply.id} className="ml-6 rounded border p-3" style={{ borderColor: 'var(--border)', background: isReplyAgent ? 'color-mix(in srgb, var(--brand-dim) 25%, var(--surface) 75%)' : 'var(--surface)' }}>
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
            {entity.logs && entity.logs.length > 0 && (
              <div>
                <button
                  className="flex items-center gap-1.5 text-xs font-medium mb-2 w-full text-left"
                  style={{ color: 'var(--muted)' }}
                  onClick={() => setLogsExpanded(!logsExpanded)}
                >
                  <FileText className="w-3.5 h-3.5" />
                  Journal d'exécution ({entity.logs.length})
                  {logsExpanded ? <ChevronUp className="w-3 h-3 ml-auto" /> : <ChevronDown className="w-3 h-3 ml-auto" />}
                </button>
                {logsExpanded && (
                  <div className="space-y-2 border-l-2 pl-3" style={{ borderColor: 'var(--border)' }}>
                    {entity.logs.map((log, i) => {
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
            {entity.tokenUsage && entity.tokenUsage.length > 0 && (
              <div>
                <p className="text-xs font-medium mb-2" style={{ color: 'var(--muted)' }}>Tokens consommés</p>
                <div className="space-y-1.5">
                  {(entity.tokenUsage as TokenUsageEntry[]).map((u) => (
                    <div key={u.id} className="flex items-center gap-3 text-xs px-3 py-2 rounded" style={{ background: 'var(--surface2)' }}>
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
        })()}
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

// ─── Main page ────────────────────────────────────────────────────────────────

type Tab = 'general' | 'board' | 'tasks' | 'team' | 'modules' | 'audit' | 'tokens'

const TABS: { key: Tab; label: string; icon: ComponentType<{ className?: string }> }[] = [
  { key: 'general', label: 'Général',      icon: Settings  },
  { key: 'board',   label: 'Board',        icon: Kanban    },
  { key: 'tasks',   label: 'Tâches',       icon: ListTodo  },
  { key: 'team',    label: 'Équipe',       icon: Users     },
  { key: 'modules', label: 'Modules',      icon: Code2     },
  { key: 'audit',   label: 'Audit',        icon: History   },
  { key: 'tokens',  label: 'Tokens',       icon: Coins     },
]

const DEFAULT_TAB: Tab = 'board'

function isProjectTab(value: string | null): value is Tab {
  return TABS.some((tab) => tab.key === value)
}

/**
 * Project detail hub page with 5 tabs: Général, Board, Tâches, Équipe, Modules.
 * Stories/bugs kanban and technical tasks are accessible directly from this page,
 * scoped to the project — no need to navigate to a global Tasks page.
 */
export default function ProjectDetailPage() {
  const { id } = useParams<{ id: string }>()
  const [searchParams, setSearchParams] = useSearchParams()
  const qc = useQueryClient()

  const [tab, setTab]                         = useState<Tab>(() => {
    const requestedTab = searchParams.get('tab')
    return isProjectTab(requestedTab) ? requestedTab : DEFAULT_TAB
  })
  const [createOpen, setCreateOpen]           = useState(false)
  const [deleteTask, setDeleteTask]           = useState<Ticket | TicketTask | null>(null)
  const [transitionError, setTransitionError] = useState<string | null>(null)
  const [pendingTaskId, setPendingTaskId]     = useState<string | null>(null)
  const [requestDispatchError, setRequestDispatchError] = useState<string | null>(null)
  // null = user hasn't changed the selection yet → falls back to project.team?.id
  const [selectedTeamId, setSelectedTeamId]   = useState<string | null>(null)
  const [teamSaveError, setTeamSaveError]     = useState<string | null>(null)
  const [auditPage, setAuditPage]             = useState(1)
  const [drawerTaskId, setDrawerTaskId]       = useState<string | null>(null)
  const [agentSheetId, setAgentSheetId]       = useState<string | null>(null)

  useEffect(() => {
    const requestedTab = searchParams.get('tab')
    const nextTab = isProjectTab(requestedTab) ? requestedTab : DEFAULT_TAB

    if (tab !== nextTab) {
      setTab(nextTab)
      return
    }

    if (!isProjectTab(requestedTab)) {
      const nextParams = new URLSearchParams(searchParams)
      nextParams.set('tab', nextTab)
      setSearchParams(nextParams, { replace: true })
    }
  }, [searchParams, setSearchParams, tab])

  useEffect(() => {
    const requestedTaskId = searchParams.get('task')
    if (requestedTaskId && requestedTaskId !== drawerTaskId) {
      setDrawerTaskId(requestedTaskId)
      return
    }

    if (!requestedTaskId && drawerTaskId !== null) {
      setDrawerTaskId(null)
    }
  }, [drawerTaskId, searchParams])

  useEffect(() => {
    const requestedAgentId = searchParams.get('agent')
    if (requestedAgentId && requestedAgentId !== agentSheetId) {
      setAgentSheetId(requestedAgentId)
      return
    }

    if (!requestedAgentId && agentSheetId !== null) {
      setAgentSheetId(null)
    }
  }, [agentSheetId, searchParams])

  const handleTabChange = (nextTab: Tab) => {
    setTab(nextTab)

    const nextParams = new URLSearchParams(searchParams)
    nextParams.set('tab', nextTab)
    setSearchParams(nextParams, { replace: true })
  }

  const openTaskDrawer = (taskId: string) => {
    setDrawerTaskId(taskId)
    const nextParams = new URLSearchParams(searchParams)
    nextParams.set('task', taskId)
    setSearchParams(nextParams, { replace: true })
  }

  const closeTaskDrawer = () => {
    setDrawerTaskId(null)
    const nextParams = new URLSearchParams(searchParams)
    nextParams.delete('task')
    setSearchParams(nextParams, { replace: true })
  }

  const openAgentSheet = (agentId: string) => {
    setAgentSheetId(agentId)
    const nextParams = new URLSearchParams(searchParams)
    nextParams.set('agent', agentId)
    setSearchParams(nextParams, { replace: true })
  }

  const closeAgentSheet = () => {
    setAgentSheetId(null)
    const nextParams = new URLSearchParams(searchParams)
    nextParams.delete('agent')
    setSearchParams(nextParams, { replace: true })
  }

  // ── Data queries ─────────────────────────────────────────────────────────────

  const { data: project, isLoading: loadingProject, isFetching: fetchingProject, error: errorProject, refetch: refetchProject } = useQuery({
    queryKey: ['projects', id],
    queryFn:  () => projectsApi.get(id!),
    enabled:  !!id,
  })

  const { data: workflow } = useQuery<Workflow | null>({
    queryKey: ['workflow', project?.workflow?.id],
    queryFn: () => workflowsApi.get(project!.workflow!.id),
    enabled: !!project?.workflow?.id,
  })
  const workflowActions = Array.isArray(workflow?.steps)
    ? Array.from(new Map(
        workflow.steps
          .flatMap((step) => step.actions.map(({ agentAction }) => [agentAction.key, { key: agentAction.key, label: agentAction.label }] as const)),
      ).values())
    : []

  const { data: tickets = [], isLoading: loadingTickets, isFetching: fetchingTickets, error: errorTickets } = useQuery({
    queryKey: ['tickets', id],
    queryFn:  () => ticketsApi.listByProject(id!),
    enabled:  !!id,
  })

  const { data: teamDetail } = useQuery({
    queryKey: ['teams', project?.team?.id],
    queryFn:  () => teamsApi.get(project!.team!.id),
    enabled:  !!project?.team?.id && tab === 'team',
  })

  const { data: teamsList = [] } = useQuery({
    queryKey: ['teams'],
    queryFn:  teamsApi.list,
    enabled:  tab === 'general',
  })
  const { data: projectTeamGuardI18n } = useQuery({
    queryKey: ['ui-translations', 'project-team-guard'],
    queryFn: () => translationsApi.list([...PROJECT_TEAM_GUARD_TRANSLATION_KEYS]),
    staleTime: Infinity,
  })

  const tt = (key: typeof PROJECT_TEAM_GUARD_TRANSLATION_KEYS[number]) => (
    projectTeamGuardI18n?.translations[key] ?? key
  )

  const { data: auditData, isLoading: loadingAudit } = useQuery({
    queryKey: ['project-audit', id, auditPage],
    queryFn:  () => projectsApi.getAudit(id!, auditPage),
    enabled:  !!id && tab === 'audit',
  })

  const { data: tokensData, isLoading: loadingTokens } = useQuery({
    queryKey: ['project-tokens', id],
    queryFn:  () => projectsApi.getTokens(id!),
    enabled:  !!id && tab === 'tokens',
  })

  const { data: projectItemI18n } = useQuery({
    queryKey: ['ui-translations', 'project-item'],
    queryFn: () => translationsApi.list(['project.item.loading']),
  })
  const ttItem = (key: string) => projectItemI18n?.translations[key] ?? key

  // ── Mutations ─────────────────────────────────────────────────────────────────

  const invalidateTickets = () => qc.invalidateQueries({ queryKey: ['tickets', id] })

  const createRequestMutation = useMutation({
    mutationFn: (d: ProjectRequestPayload) => ticketsApi.createRequest(id!, d),
    onSuccess:  (result: ProjectRequestResult) => {
      invalidateTickets()
      setCreateOpen(false)
      setRequestDispatchError(result.dispatchError ?? null)
    },
    onError: (err: unknown) => {
      const msg = (err as { message?: string })?.message
      setRequestDispatchError(msg ?? (projectTeamGuardI18n?.translations['projects.progress.error.request_creation_failed'] ?? 'projects.progress.error.request_creation_failed'))
    },
  })

  const createMutation = useMutation({
    mutationFn: (d: TicketTaskPayload & { ticketId?: string }) => {
      if (!d.ticketId) {
        throw new Error('ticketId manquant pour la création d’une tâche technique.')
      }

      return ticketTasksApi.create(d.ticketId, {
        title: d.title,
        description: d.description,
        priority: d.priority,
        actionKey: d.actionKey,
        assignedAgentId: d.assignedAgentId,
        parentTaskId: d.parentTaskId,
      })
    },
    onSuccess:  () => { invalidateTickets(); setCreateOpen(false) },
  })

  const transitionMutation = useMutation({
    mutationFn: ({ taskId }: { taskId: string }) => {
      setPendingTaskId(taskId)
      setTransitionError(null)
      return ticketsApi.advance(taskId)
    },
    onSuccess: () => { invalidateTickets(); setPendingTaskId(null) },
    onError: (err: unknown) => {
      setPendingTaskId(null)
      const msg = (err as { response?: { data?: { error?: string } } })?.response?.data?.error
      setTransitionError(msg ?? 'Transition impossible.')
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (entity: Ticket | TicketTask) => (
      isTicket(entity) ? ticketsApi.delete(entity.id) : ticketTasksApi.delete(entity.id)
    ),
    onSuccess:  () => { invalidateTickets(); setDeleteTask(null) },
  })

  const updateTeamMutation = useMutation({
    mutationFn: (teamId: string | null) => projectsApi.update(id!, {
      name:        project!.name,
      description: project!.description ?? undefined,
      teamId:      teamId,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['projects', id] })
      setTeamSaveError(null)
    },
    onError: () => setTeamSaveError('Impossible de sauvegarder l\'équipe.'),
  })

  const projectNeedsTeamAssignment = project?.team === null
  const projectProgressBlockedReason = projectNeedsTeamAssignment
    ? (projectTeamGuardI18n?.translations['projects.progress.ui.blocked_reason'] ?? 'projects.progress.ui.blocked_reason')
    : null

  const refreshAllData = () => {
    if( !id ) return
    qc.invalidateQueries({ queryKey: ['projects', id] })
    qc.invalidateQueries({ queryKey: ['tickets', id] })
    qc.invalidateQueries({ queryKey: ['workflow'] })
    qc.invalidateQueries({ queryKey: ['teams'] })
    qc.invalidateQueries({ queryKey: ['project-audit', id] })
    qc.invalidateQueries({ queryKey: ['project-tokens', id] })
  }

  // ── Derived data ───────────────────────────────────────────────────────────────

  if (loadingProject) return <PageSpinner />
  if (errorProject || !project) {
    return <ErrorMessage message={(errorProject as Error)?.message ?? 'Projet introuvable'} onRetry={() => refetchProject()} />
  }

  const stories = tickets
  const taskMap = new Map<string, TicketTask>()
  const techTasks = tickets.flatMap((ticket) => {
    const items = ticket.tasks ?? []
    items.forEach((task) => taskMap.set(task.id, task))
    return items
  })
  const modules   = Array.isArray(project.modules) ? (project.modules as Module[]) : []

  // ── Render ─────────────────────────────────────────────────────────────────────

  return (
    <>
      <Link to="/projects" className="inline-flex items-center gap-1 text-sm mb-4" style={{ color: 'var(--muted)' }}>
        <ArrowLeft className="w-4 h-4" /> Projets
      </Link>

      <PageHeader
        title={project.name}
        description={project.description ?? undefined}
        onRefresh={refreshAllData}
        refreshTitle={tt('common.action.refresh')}
        action={
          (tab === 'board' || tab === 'tasks') ? (
            <button
              className="btn-primary"
              disabled={tab === 'board' && projectNeedsTeamAssignment}
              title={tab === 'board' ? (projectProgressBlockedReason ?? undefined) : undefined}
              onClick={() => {
              if (tab === 'board' && projectNeedsTeamAssignment) return
              setCreateOpen(true)
              if (tab === 'board') setRequestDispatchError(null)
            }}>
              <Plus className="w-4 h-4" />
              {tab === 'board' ? 'Nouvelle demande' : 'Nouvelle tâche'}
            </button>
          ) : undefined
        }
      />

      {/* Stats */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
        {[
          { label: 'Stories & Bugs', value: stories.length },
          { label: 'Tâches',         value: techTasks.length },
          { label: 'Modules',        value: modules.length },
          { label: 'Équipe',         value: project.team?.name ?? '—' },
        ].map(({ label, value }) => (
          <div key={label} className="card p-3 text-center">
            <p className="text-xl font-bold" style={{ color: 'var(--text)' }}>{value}</p>
            <p className="text-xs mt-0.5" style={{ color: 'var(--muted)' }}>{label}</p>
          </div>
        ))}
      </div>

      {projectNeedsTeamAssignment && (
        <div className="mb-5 rounded border px-4 py-3 text-sm" style={{ borderColor: 'rgba(245,158,11,0.35)', background: 'rgba(245,158,11,0.08)', color: '#92400e' }}>
          {projectTeamGuardI18n?.translations['projects.progress.ui.banner'] ?? 'projects.progress.ui.banner'}
        </div>
      )}

      {/* Tab bar */}
      <div className="flex gap-0 mb-5 border-b" style={{ borderColor: 'var(--border)' }}>
        {TABS.map(({ key, label, icon: Icon }) => (
          <button
            key={key}
            onClick={() => handleTabChange(key)}
            className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium transition-colors -mb-px border-b-2"
            style={{
              borderColor: tab === key ? 'var(--brand)' : 'transparent',
              color:       tab === key ? 'var(--brand)' : 'var(--muted)',
            }}
          >
            <Icon className="w-3.5 h-3.5" />
            {label}
          </button>
        ))}
      </div>

      {/* ── Tab: Général ── */}
      {tab === 'general' && (
        <div className="max-w-lg space-y-6">
          <div className="card p-5 space-y-4">
            <h3 className="text-sm font-semibold" style={{ color: 'var(--text)' }}>Informations</h3>
            <div>
              <p className="text-xs mb-0.5" style={{ color: 'var(--muted)' }}>Nom</p>
              <p className="text-sm font-medium" style={{ color: 'var(--text)' }}>{project.name}</p>
            </div>
            {project.description && (
              <div>
                <p className="text-xs mb-0.5" style={{ color: 'var(--muted)' }}>Description</p>
                <p className="text-sm" style={{ color: 'var(--text)' }}>{project.description}</p>
              </div>
            )}
          </div>

          <div className="card p-5 space-y-4">
            <h3 className="text-sm font-semibold" style={{ color: 'var(--text)' }}>Équipe assignée</h3>
            <p className="text-xs" style={{ color: 'var(--muted)' }}>
              L'équipe détermine les agents disponibles pour l'exécution des stories.
            </p>
            <div>
              <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Équipe</label>
              <select
                className="input"
                value={selectedTeamId ?? (project.team?.id ?? '')}
                onChange={(e) => setSelectedTeamId(e.target.value)}
              >
                <option value="">— Aucune équipe —</option>
                {teamsList.map((t) => (
                  <option key={t.id} value={t.id}>{t.name}</option>
                ))}
              </select>
            </div>
            {teamSaveError && (
              <p className="text-sm text-red-600 flex items-center gap-1">
                <AlertCircle className="w-4 h-4" />{teamSaveError}
              </p>
            )}
            <button
              className="btn-primary"
              disabled={updateTeamMutation.isPending}
              onClick={() => {
                const effectiveId = selectedTeamId ?? (project.team?.id ?? '')
                updateTeamMutation.mutate(effectiveId || null)
              }}
            >
              {updateTeamMutation.isPending ? 'Enregistrement…' : 'Enregistrer'}
            </button>
          </div>
        </div>
      )}

      {/* ── Tab: Board ── */}
      {tab === 'board' && (
        <>
          {requestDispatchError && (
            <div className="mb-3 px-3 py-2 rounded flex items-center justify-between text-sm" style={{ background: 'rgba(239,68,68,0.1)', color: '#dc2626', border: '1px solid rgba(239,68,68,0.3)' }}>
              <span>L'agent Product Owner n'a pas pu prendre la demande. {requestDispatchError}</span>
              <button onClick={() => setRequestDispatchError(null)}><XCircle className="w-4 h-4" /></button>
            </div>
          )}
          {transitionError && (
            <div className="mb-3 px-3 py-2 rounded flex items-center justify-between text-sm" style={{ background: 'rgba(239,68,68,0.1)', color: '#dc2626', border: '1px solid rgba(239,68,68,0.3)' }}>
              <span>{transitionError}</span>
              <button onClick={() => setTransitionError(null)}><XCircle className="w-4 h-4" /></button>
            </div>
          )}
          {loadingTickets ? <PageSpinner /> : errorTickets ? (
            <ErrorMessage message={(errorTickets as Error).message} />
          ) : (
            <StoryBoard
              tickets={stories}
              steps={Array.isArray(workflow?.steps) ? workflow.steps.map((step) => ({ id: step.id, key: step.outputKey, name: step.name })) : []}
              onTransition={(ticket) => transitionMutation.mutate({ taskId: ticket.id })}
              onDelete={setDeleteTask}
              onOpen={(ticket) => openTaskDrawer(ticket.id)}
              pendingTaskId={pendingTaskId}
              progressBlockedReason={projectProgressBlockedReason}
            />
          )}
        </>
      )}

      {/* ── Tab: Tâches ── */}
      {tab === 'tasks' && (
        loadingTickets ? <PageSpinner /> : techTasks.length === 0 ? (
          <EmptyState
            icon={ListTodo}
            title="Aucune tâche technique"
            description="Les tâches techniques sont créées automatiquement lors de la planification d'une story, ou manuellement."
          />
        ) : (
          <div className="card divide-y" style={{ borderColor: 'var(--border)' }}>
            {techTasks.map((t) => (
              <TechTaskRow
                key={t.id}
                task={t}
                parent={t.parentTaskId ? (taskMap.get(t.parentTaskId) ?? tickets.find((ticket) => ticket.id === t.ticketId) ?? null) : tickets.find((ticket) => ticket.id === t.ticketId) ?? null}
                onDelete={setDeleteTask}
                onOpen={(task) => openTaskDrawer(task.id)}
              />
            ))}
          </div>
        )
      )}

      {/* ── Tab: Équipe ── */}
      {tab === 'team' && (
        !project.team ? (
          <EmptyState
            icon={Users}
            title="Aucune équipe assignée"
            description="Assignez une équipe dans l'onglet Général pour voir les agents disponibles."
            action={<button className="btn-secondary" onClick={() => handleTabChange('general')}><Settings className="w-4 h-4" /> Configurer</button>}
          />
        ) : !teamDetail ? (
          <PageSpinner />
        ) : (
          <div className="space-y-4">
            <div className="flex items-center gap-3 mb-2">
              <h3 className="text-sm font-semibold" style={{ color: 'var(--text)' }}>{teamDetail.name}</h3>
              {teamDetail.description && (
                <span className="text-sm" style={{ color: 'var(--muted)' }}>{teamDetail.description}</span>
              )}
            </div>
            {(!teamDetail.agents || teamDetail.agents.length === 0) ? (
              <EmptyState
                icon={Users}
                title="Aucun agent dans cette équipe"
                description="Ajoutez des agents depuis la page Équipes."
              />
            ) : (
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {(teamDetail.agents as AgentSummary[]).map((agent) => (
                  <button
                    key={agent.id}
                    type="button"
                    className="card p-4 flex items-center gap-3 text-left hover:border-[var(--brand)] transition-colors"
                    onClick={() => openAgentSheet(agent.id)}
                  >
                    <div
                      className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-semibold"
                      style={{ background: 'var(--brand-dim)', color: 'var(--brand)' }}
                    >
                      {agent.name.charAt(0).toUpperCase()}
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <p className="text-sm font-medium truncate" style={{ color: 'var(--text)' }}>{agent.name}</p>
                        <AgentStatusBadge agentId={agent.id} />
                      </div>
                      {agent.role && (
                        <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--muted)' }}>{agent.role.name}</p>
                      )}
                      {!agent.isActive && (
                        <span className="badge-gray text-xs mt-1 inline-block">Inactif</span>
                      )}
                    </div>
                    <Zap className="w-4 h-4 flex-shrink-0" style={{ color: agent.isActive ? 'var(--brand)' : 'var(--muted)', opacity: agent.isActive ? 1 : 0.3 }} />
                  </button>
                ))}
              </div>
            )}
          </div>
        )
      )}

      {/* ── Tab: Modules ── */}
      {tab === 'modules' && (
        modules.length === 0 ? (
          <EmptyState icon={Code2} title="Aucun module" description="Les modules représentent les composants logiciels du projet (API, client mobile, etc.)." />
        ) : (
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {modules.map((mod) => (
              <div key={mod.id} className="card p-4 flex flex-col gap-2">
                <p className="font-medium text-sm" style={{ color: 'var(--text)' }}>{mod.name}</p>
                {mod.description && <p className="text-xs" style={{ color: 'var(--muted)' }}>{mod.description}</p>}
                {mod.stack && <span className="badge-blue self-start">{mod.stack}</span>}
                {mod.repositoryUrl && (
                  <a href={mod.repositoryUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs" style={{ color: 'var(--brand)' }}>
                    <Globe className="w-3 h-3" /> Dépôt
                  </a>
                )}
                <div className="mt-auto">
                  <span className={mod.status === 'active' ? 'badge-green' : 'badge-gray'}>
                    {mod.status === 'active' ? 'Actif' : 'Archivé'}
                  </span>
                </div>
              </div>
            ))}
          </div>
        )
      )}

      {/* ── Tab: Audit ── */}
      {tab === 'audit' && (
        loadingAudit ? <PageSpinner /> : !auditData || auditData.data.length === 0 ? (
          <EmptyState icon={History} title="Aucune entrée d'audit" description="Les actions sur ce projet et ses tâches apparaîtront ici." />
        ) : (
          <div className="space-y-3">
            <div className="card divide-y" style={{ borderColor: 'var(--border)' }}>
              {auditData.data.map((entry) => (
                <div key={entry.id} className="px-4 py-3 flex items-start gap-3">
                  <div className="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5" style={{ background: 'var(--brand-dim)' }}>
                    <History className="w-3.5 h-3.5" style={{ color: 'var(--brand)' }} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium" style={{ color: 'var(--text)' }}>
                      {AUDIT_ACTION_LABELS[entry.action] ?? entry.action}
                    </p>
                    <div className="flex items-center gap-2 mt-0.5">
                      <span className="text-xs" style={{ color: 'var(--muted)' }}>{entry.entityType}</span>
                      {entry.data && Object.keys(entry.data).length > 0 && (
                        <span className="text-xs" style={{ color: 'var(--muted)' }}>
                          {Object.entries(entry.data).map(([k, v]) => `${k}: ${String(v)}`).join(', ')}
                        </span>
                      )}
                    </div>
                  </div>
                  <span className="text-xs flex-shrink-0" style={{ color: 'var(--muted)' }}>
                    {new Date(entry.createdAt).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}
                  </span>
                </div>
              ))}
            </div>
            {/* Pagination */}
            {auditData.total > auditData.limit && (
              <div className="flex items-center justify-between text-sm" style={{ color: 'var(--muted)' }}>
                <span>{auditData.total} entrées</span>
                <div className="flex gap-2">
                  <button
                    className="btn-secondary py-1"
                    disabled={auditPage <= 1}
                    onClick={() => setAuditPage(p => p - 1)}
                  >Précédent</button>
                  <span className="px-2 py-1">{auditPage} / {Math.ceil(auditData.total / auditData.limit)}</span>
                  <button
                    className="btn-secondary py-1"
                    disabled={auditPage >= Math.ceil(auditData.total / auditData.limit)}
                    onClick={() => setAuditPage(p => p + 1)}
                  >Suivant</button>
                </div>
              </div>
            )}
          </div>
        )
      )}

      {/* ── Tab: Tokens ── */}
      {tab === 'tokens' && (
        loadingTokens ? <PageSpinner /> : !tokensData ? null : (
          <div className="space-y-5">
            {/* Summary */}
            <div className="grid grid-cols-3 gap-3">
              {[
                { label: 'Tokens entrée', value: tokensData.summary.total.input.toLocaleString() },
                { label: 'Tokens sortie', value: tokensData.summary.total.output.toLocaleString() },
                { label: 'Appels',        value: tokensData.summary.total.calls },
              ].map(({ label, value }) => (
                <div key={label} className="card p-4 text-center">
                  <p className="text-xl font-bold" style={{ color: 'var(--text)' }}>{value}</p>
                  <p className="text-xs mt-0.5" style={{ color: 'var(--muted)' }}>{label}</p>
                </div>
              ))}
            </div>

            {/* By agent */}
            {tokensData.summary.byAgent.length > 0 && (
              <div className="card overflow-hidden">
                <div className="px-4 py-2 border-b text-xs font-semibold" style={{ color: 'var(--muted)', borderColor: 'var(--border)' }}>
                  Répartition par agent
                </div>
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-xs border-b" style={{ color: 'var(--muted)', borderColor: 'var(--border)' }}>
                      <th className="px-4 py-2 text-left font-medium">Agent</th>
                      <th className="px-4 py-2 text-right font-medium">Entrée</th>
                      <th className="px-4 py-2 text-right font-medium">Sortie</th>
                      <th className="px-4 py-2 text-right font-medium">Appels</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y" style={{ borderColor: 'var(--border)' }}>
                    {tokensData.summary.byAgent.map((row) => (
                      <tr key={row.agentId ?? 'unknown'}>
                        <td className="px-4 py-2 font-medium" style={{ color: 'var(--text)' }}>{row.agentName}</td>
                        <td className="px-4 py-2 text-right" style={{ color: 'var(--muted)' }}>{row.totalInput.toLocaleString()}</td>
                        <td className="px-4 py-2 text-right" style={{ color: 'var(--muted)' }}>{row.totalOutput.toLocaleString()}</td>
                        <td className="px-4 py-2 text-right" style={{ color: 'var(--muted)' }}>{row.calls}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {/* Recent entries */}
            {tokensData.entries.length > 0 && (
              <div className="card overflow-hidden">
                <div className="px-4 py-2 border-b text-xs font-semibold" style={{ color: 'var(--muted)', borderColor: 'var(--border)' }}>
                  Entrées récentes
                </div>
                <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
                  {tokensData.entries.map((u) => (
                    <div key={u.id} className="px-4 py-3 flex items-center gap-3 text-sm">
                      <div className="flex-1 min-w-0">
                        <p className="truncate font-medium" style={{ color: 'var(--text)' }}>{u.task?.title ?? '—'}</p>
                        <p className="text-xs" style={{ color: 'var(--muted)' }}>{u.model}</p>
                      </div>
                      <div className="text-right flex-shrink-0">
                        <p className="font-medium" style={{ color: 'var(--brand)' }}>{u.totalTokens.toLocaleString()} tok</p>
                        {u.durationMs !== null && (
                          <p className="text-xs" style={{ color: 'var(--muted)' }}>{(u.durationMs / 1000).toFixed(1)}s</p>
                        )}
                      </div>
                      <span className="text-xs flex-shrink-0" style={{ color: 'var(--muted)' }}>
                        {new Date(u.createdAt).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {tokensData.summary.total.calls === 0 && (
              <EmptyState icon={Coins} title="Aucune consommation" description="Les tokens consommés par les agents sur ce projet s'afficheront ici." />
            )}
          </div>
        )
      )}

      {/* ── Modals ── */}
      <Modal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title={tab === 'board' ? 'Nouvelle demande' : 'Nouvelle tâche technique'}
      >
        {tab === 'board' ? (
          <RequestForm
            onSubmit={(d) => createRequestMutation.mutate(d)}
            loading={createRequestMutation.isPending}
            onCancel={() => setCreateOpen(false)}
          />
        ) : (
          <TaskForm
            initial={{ type: 'task' }}
            onSubmit={(d) => createMutation.mutate(d)}
            loading={createMutation.isPending}
            onCancel={() => setCreateOpen(false)}
            tickets={stories.map((story) => ({ id: story.id, title: story.title }))}
            actions={workflowActions}
          />
        )}
      </Modal>

      <ConfirmDialog
        open={!!deleteTask}
        onClose={() => setDeleteTask(null)}
        onConfirm={() => deleteTask && deleteMutation.mutate(deleteTask)}
        message={`Supprimer "${deleteTask?.title}" ? Cette action est irréversible.`}
        loading={deleteMutation.isPending}
      />

      <div className="relative">
        <ContentLoadingOverlay isLoading={(fetchingProject || fetchingTickets) && !loadingProject && !loadingTickets} label={ttItem('project.item.loading')}       />

      </div>

      {/* ── Task drawer ── */}
      {drawerTaskId && (
        <TaskDrawer taskId={drawerTaskId} onClose={closeTaskDrawer} projectHasTeam={!projectNeedsTeamAssignment} />
      )}

      {id && (
        <AgentSheet
          projectId={id}
          agentId={agentSheetId}
          open={agentSheetId !== null}
          onClose={closeAgentSheet}
        />
      )}
    </>
  )
}
