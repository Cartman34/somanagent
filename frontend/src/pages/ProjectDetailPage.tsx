import { useState, type ComponentType } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft, Plus, Code2, Globe, XCircle, CheckCircle, Clock,
  AlertTriangle, ChevronRight, GitBranch, User, ArrowRight,
  Layers, Play, ListTodo, Users, Settings, Kanban, Zap,
  Loader2, AlertCircle,
} from 'lucide-react'
import { projectsApi } from '@/api/projects'
import { tasksApi } from '@/api/tasks'
import { teamsApi } from '@/api/teams'
import { agentsApi } from '@/api/agents'
import type { TaskPayload } from '@/api/tasks'
import type { Task, TaskStatus, TaskPriority, TaskType, StoryStatus, Module, AgentSummary } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'

// ─── Constants ────────────────────────────────────────────────────────────────

const TYPE_BADGE:  Record<TaskType, string>    = { user_story: 'badge-blue', bug: 'badge-red', task: 'badge-green' }
const TYPE_LABELS: Record<TaskType, string>    = { user_story: 'US', bug: 'Bug', task: 'Tâche' }
const PRIORITY_LABELS: Record<TaskPriority, string> = { low: 'Faible', medium: 'Normale', high: 'Haute', critical: 'Critique' }
const PRIORITY_COLOR:  Record<TaskPriority, string> = { low: 'text-gray-400', medium: 'text-blue-500', high: 'text-orange-500', critical: 'text-red-600' }
const STATUS_LABELS: Record<TaskStatus, string> = {
  backlog: 'Backlog', todo: 'À faire', in_progress: 'En cours',
  review: 'Revue', done: 'Terminé', cancelled: 'Annulé',
}

const STORY_COLUMNS: { key: StoryStatus; label: string; color: string; bg: string }[] = [
  { key: 'new',            label: 'Nouvelle',      color: 'text-gray-500',   bg: 'bg-gray-50'   },
  { key: 'ready',          label: 'Prête',          color: 'text-blue-500',   bg: 'bg-blue-50'   },
  { key: 'approved',       label: 'Approuvée',      color: 'text-indigo-500', bg: 'bg-indigo-50' },
  { key: 'planning',       label: 'Planification',  color: 'text-purple-500', bg: 'bg-purple-50' },
  { key: 'graphic_design', label: 'Conception',     color: 'text-pink-500',   bg: 'bg-pink-50'   },
  { key: 'development',    label: 'Développement',  color: 'text-orange-500', bg: 'bg-orange-50' },
  { key: 'code_review',    label: 'Revue de code',  color: 'text-yellow-600', bg: 'bg-yellow-50' },
  { key: 'done',           label: 'Terminée',       color: 'text-green-600',  bg: 'bg-green-50'  },
]

const STORY_TRANSITION_LABELS: Partial<Record<StoryStatus, string>> = {
  ready: 'Marquer prête', approved: 'Approuver', planning: 'Lancer planification',
  graphic_design: 'Conception graphique', development: 'Développement',
  code_review: 'Revue de code', done: 'Terminer',
}

const EXECUTABLE_STATUSES: StoryStatus[] = ['approved', 'graphic_design', 'development', 'code_review']

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

// ─── Execute modal ────────────────────────────────────────────────────────────

/**
 * Modal shown before dispatching a story to an agent.
 * Fetches available agents for the story's current status, lets the user pick one,
 * then dispatches via POST /tasks/{id}/execute.
 */
function ExecuteModal({ task, onClose, onExecuted }: {
  task: Task
  onClose: () => void
  onExecuted: () => void
}) {
  const [selectedAgentId, setSelectedAgentId] = useState('')
  const [result, setResult] = useState<{ agentName: string; skill: string } | null>(null)

  const { data: agents, isLoading: loadingAgents } = useQuery({
    queryKey: ['task-execute-agents', task.id],
    queryFn:  () => tasksApi.listExecuteAgents(task.id),
  })

  const executeMutation = useMutation({
    mutationFn: () => tasksApi.execute(task.id, selectedAgentId || undefined),
    onSuccess: (data) => { setResult({ agentName: data.agent.name, skill: data.skill }); onExecuted() },
  })

  if (result) {
    return (
      <div className="space-y-4 text-sm text-center py-4">
        <CheckCircle className="w-10 h-10 text-green-500 mx-auto" />
        <p className="font-medium text-gray-900">Agent dispatché</p>
        <p className="text-gray-500">
          <strong>{result.agentName}</strong> exécute <code className="bg-gray-100 px-1 rounded">{result.skill}</code>
        </p>
        <button onClick={onClose} className="btn-primary mx-auto">Fermer</button>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-gray-600">
        Sélectionnez un agent pour exécuter <strong>"{task.title}"</strong>.
        L'agent sera choisi automatiquement si vous ne sélectionnez pas.
      </p>
      {loadingAgents ? (
        <p className="text-sm text-gray-400">Chargement des agents…</p>
      ) : agents && agents.length > 0 ? (
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Agent</label>
          <select className="input" value={selectedAgentId} onChange={(e) => setSelectedAgentId(e.target.value)}>
            <option value="">— Auto-sélection —</option>
            {agents.map((a) => (
              <option key={a.id} value={a.id}>{a.name}{a.role ? ` (${a.role.name})` : ''}</option>
            ))}
          </select>
        </div>
      ) : (
        <p className="text-sm text-red-600">Aucun agent disponible avec le rôle requis.</p>
      )}
      {executeMutation.isError && (
        <p className="text-sm text-red-600">
          {(executeMutation.error as { response?: { data?: { error?: string } } })?.response?.data?.error ?? 'Erreur lors de l\'exécution.'}
        </p>
      )}
      <div className="flex justify-end gap-3 pt-2">
        <button onClick={onClose} className="btn-secondary">Annuler</button>
        <button
          onClick={() => executeMutation.mutate()}
          disabled={executeMutation.isPending || agents?.length === 0}
          className="btn-primary"
        >
          {executeMutation.isPending ? 'Dispatch…' : "Lancer l'agent"}
        </button>
      </div>
    </div>
  )
}

// ─── Story card (Kanban) ──────────────────────────────────────────────────────

/**
 * Kanban card for a user story or bug.
 * Shows type badge, priority, branch name, assigned role, progress bar,
 * allowed story transitions as buttons, and a "Lancer l'agent" button for executable statuses.
 */
function StoryCard({ task, onTransition, onDelete, onExecute, transitioning }: {
  task: Task
  onTransition: (task: Task, status: StoryStatus) => void
  onDelete: (task: Task) => void
  onExecute: (task: Task) => void
  transitioning: boolean
}) {
  const canExecute = task.storyStatus !== null && EXECUTABLE_STATUSES.includes(task.storyStatus)

  return (
    <div className="card p-3 space-y-2 text-sm">
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-1.5 flex-wrap">
          <span className={`${TYPE_BADGE[task.type]} text-xs`}>{TYPE_LABELS[task.type]}</span>
          <span className={`text-xs font-medium ${PRIORITY_COLOR[task.priority]}`}>{PRIORITY_LABELS[task.priority]}</span>
        </div>
        <button onClick={() => onDelete(task)} className="p-0.5 text-gray-300 hover:text-red-400 flex-shrink-0" title="Supprimer">
          <XCircle className="w-3.5 h-3.5" />
        </button>
      </div>

      <p className="font-medium leading-snug" style={{ color: 'var(--text)' }}>{task.title}</p>

      {task.branchName && (
        <div className="flex items-center gap-1 text-xs" style={{ color: 'var(--muted)' }}>
          <GitBranch className="w-3 h-3" />
          <code className="font-mono truncate">{task.branchName}</code>
        </div>
      )}
      {task.assignedRole && (
        <div className="flex items-center gap-1 text-xs" style={{ color: 'var(--muted)' }}>
          <User className="w-3 h-3" /><span>{task.assignedRole.name}</span>
        </div>
      )}
      {task.progress > 0 && (
        <div className="space-y-0.5">
          <ProgressBar value={task.progress} />
          <span className="text-xs" style={{ color: 'var(--muted)' }}>{task.progress}%</span>
        </div>
      )}
      {(task.storyStatusAllowedTransitions.length > 0 || canExecute) && (
        <div className="flex flex-wrap gap-1 pt-1 border-t" style={{ borderColor: 'var(--border)' }}>
          {task.storyStatusAllowedTransitions.map((next) => (
            <button
              key={next}
              onClick={() => onTransition(task, next)}
              disabled={transitioning}
              className="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded border hover:border-[var(--brand)] hover:text-[var(--brand)] transition-colors disabled:opacity-40 disabled:cursor-wait"
              style={{ borderColor: 'var(--border)', color: 'var(--muted)' }}
            >
              <ArrowRight className="w-2.5 h-2.5" />
              {transitioning ? '…' : (STORY_TRANSITION_LABELS[next] ?? next)}
            </button>
          ))}
          {canExecute && (
            <button
              onClick={() => onExecute(task)}
              className="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded"
              style={{ background: 'var(--brand)', color: 'white' }}
            >
              <Play className="w-2.5 h-2.5" /> Lancer l'agent
            </button>
          )}
        </div>
      )}
    </div>
  )
}

// ─── Story board (Kanban) ─────────────────────────────────────────────────────

/**
 * Kanban board with one column per StoryStatus.
 * The `pendingTaskId` disables transition buttons on the card being updated to prevent double-clicks.
 */
function StoryBoard({ stories, onTransition, onDelete, onExecute, pendingTaskId }: {
  stories: Task[]
  onTransition: (task: Task, status: StoryStatus) => void
  onDelete: (task: Task) => void
  onExecute: (task: Task) => void
  pendingTaskId: string | null
}) {
  if (stories.length === 0) {
    return <EmptyState icon={Layers} title="Aucune story ni bug" description="Créez une user story ou un bug via le bouton ci-dessus." />
  }
  return (
    <div className="flex gap-3 overflow-x-auto pb-4">
      {STORY_COLUMNS.map((col) => {
        const cards = stories.filter((s) => s.storyStatus === col.key)
        return (
          <div key={col.key} className="flex-shrink-0 w-60">
            <div className={`flex items-center justify-between px-3 py-1.5 rounded-t-lg ${col.bg}`}>
              <span className={`text-xs font-semibold ${col.color}`}>{col.label}</span>
              {cards.length > 0 && <span className={`text-xs font-medium ${col.color} opacity-60`}>{cards.length}</span>}
            </div>
            <div className="space-y-2 min-h-16 rounded-b-lg p-2 border border-t-0" style={{ background: 'var(--surface2)', borderColor: 'var(--border)' }}>
              {cards.map((task) => (
                <StoryCard
                  key={task.id}
                  task={task}
                  onTransition={onTransition}
                  onDelete={onDelete}
                  onExecute={onExecute}
                  transitioning={pendingTaskId === task.id}
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
function TechTaskRow({ task, onDelete }: { task: Task; onDelete: (t: Task) => void }) {
  return (
    <div className="px-4 py-3 flex items-center gap-3">
      <StatusIcon status={task.status} />
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className={`${TYPE_BADGE[task.type]} text-xs`}>{TYPE_LABELS[task.type]}</span>
          <p className="text-sm font-medium truncate" style={{ color: 'var(--text)' }}>{task.title}</p>
        </div>
        <div className="flex items-center gap-3 mt-0.5 flex-wrap">
          <span className="text-xs" style={{ color: 'var(--muted)' }}>{STATUS_LABELS[task.status]}</span>
          <span className={`text-xs font-medium ${PRIORITY_COLOR[task.priority]}`}>{PRIORITY_LABELS[task.priority]}</span>
          {task.assignedAgent && <span className="text-xs" style={{ color: 'var(--muted)' }}>→ {task.assignedAgent.name}</span>}
          {task.assignedRole  && <span className="text-xs italic" style={{ color: 'var(--muted)' }}>({task.assignedRole.name})</span>}
          {task.parent        && <span className="text-xs opacity-50" style={{ color: 'var(--muted)' }}>↑ {task.parent.title}</span>}
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

function TaskForm({ initial, onSubmit, loading, onCancel }: {
  initial?: Partial<TaskPayload>
  onSubmit: (d: TaskPayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const [title, setTitle]             = useState(initial?.title ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [type, setType]               = useState<TaskType>(initial?.type ?? 'user_story')
  const [priority, setPriority]       = useState<TaskPriority>(initial?.priority ?? 'medium')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ title, description: description || undefined, type, priority }) }} className="space-y-4">
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
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Description</label>
        <textarea className="input resize-none" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Annuler</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? 'Enregistrement…' : 'Enregistrer'}</button>
      </div>
    </form>
  )
}

// ─── Main page ────────────────────────────────────────────────────────────────

type Tab = 'general' | 'board' | 'tasks' | 'team' | 'modules'

const TABS: { key: Tab; label: string; icon: ComponentType<{ className?: string }> }[] = [
  { key: 'general', label: 'Général',      icon: Settings  },
  { key: 'board',   label: 'Board',        icon: Kanban    },
  { key: 'tasks',   label: 'Tâches',       icon: ListTodo  },
  { key: 'team',    label: 'Équipe',       icon: Users     },
  { key: 'modules', label: 'Modules',      icon: Code2     },
]

/**
 * Project detail hub page with 5 tabs: Général, Board, Tâches, Équipe, Modules.
 * Stories/bugs kanban and technical tasks are accessible directly from this page,
 * scoped to the project — no need to navigate to a global Tasks page.
 */
export default function ProjectDetailPage() {
  const { id } = useParams<{ id: string }>()
  const qc = useQueryClient()

  const [tab, setTab]                         = useState<Tab>('board')
  const [createOpen, setCreateOpen]           = useState(false)
  const [deleteTask, setDeleteTask]           = useState<Task | null>(null)
  const [transitionError, setTransitionError] = useState<string | null>(null)
  const [pendingTaskId, setPendingTaskId]     = useState<string | null>(null)
  const [executeTask, setExecuteTask]         = useState<Task | null>(null)
  // null = user hasn't changed the selection yet → falls back to project.team?.id
  const [selectedTeamId, setSelectedTeamId]   = useState<string | null>(null)
  const [teamSaveError, setTeamSaveError]     = useState<string | null>(null)

  // ── Data queries ─────────────────────────────────────────────────────────────

  const { data: project, isLoading: loadingProject, error: errorProject, refetch: refetchProject } = useQuery({
    queryKey: ['projects', id],
    queryFn:  () => projectsApi.get(id!),
    enabled:  !!id,
  })

  const { data: tasks = [], isLoading: loadingTasks, error: errorTasks } = useQuery({
    queryKey: ['tasks', id],
    queryFn:  () => tasksApi.listByProject(id!),
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

  // ── Mutations ─────────────────────────────────────────────────────────────────

  const invalidateTasks = () => qc.invalidateQueries({ queryKey: ['tasks', id] })

  const createMutation = useMutation({
    mutationFn: (d: TaskPayload) => tasksApi.create(id!, d),
    onSuccess:  () => { invalidateTasks(); setCreateOpen(false) },
  })

  const transitionMutation = useMutation({
    mutationFn: ({ taskId, status }: { taskId: string; status: StoryStatus }) => {
      setPendingTaskId(taskId)
      setTransitionError(null)
      return tasksApi.transitionStory(taskId, status)
    },
    onSuccess: () => { invalidateTasks(); setPendingTaskId(null) },
    onError: (err: unknown) => {
      setPendingTaskId(null)
      const msg = (err as { response?: { data?: { error?: string } } })?.response?.data?.error
      setTransitionError(msg ?? 'Transition impossible.')
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (taskId: string) => tasksApi.delete(taskId),
    onSuccess:  () => { invalidateTasks(); setDeleteTask(null) },
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

  // ── Derived data ───────────────────────────────────────────────────────────────

  if (loadingProject) return <PageSpinner />
  if (errorProject || !project) {
    return <ErrorMessage message={(errorProject as Error)?.message ?? 'Projet introuvable'} onRetry={() => refetchProject()} />
  }

  const stories   = tasks.filter((t) => t.type === 'user_story' || t.type === 'bug')
  const techTasks = tasks.filter((t) => t.type === 'task')
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
        action={
          (tab === 'board' || tab === 'tasks') ? (
            <button className="btn-primary" onClick={() => setCreateOpen(true)}>
              <Plus className="w-4 h-4" />
              {tab === 'board' ? 'Nouvelle story' : 'Nouvelle tâche'}
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

      {/* Tab bar */}
      <div className="flex gap-0 mb-5 border-b" style={{ borderColor: 'var(--border)' }}>
        {TABS.map(({ key, label, icon: Icon }) => (
          <button
            key={key}
            onClick={() => setTab(key)}
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
          {transitionError && (
            <div className="mb-3 px-3 py-2 rounded flex items-center justify-between text-sm" style={{ background: 'rgba(239,68,68,0.1)', color: '#dc2626', border: '1px solid rgba(239,68,68,0.3)' }}>
              <span>{transitionError}</span>
              <button onClick={() => setTransitionError(null)}><XCircle className="w-4 h-4" /></button>
            </div>
          )}
          {loadingTasks ? <PageSpinner /> : errorTasks ? (
            <ErrorMessage message={(errorTasks as Error).message} />
          ) : (
            <StoryBoard
              stories={stories}
              onTransition={(task, status) => transitionMutation.mutate({ taskId: task.id, status })}
              onDelete={setDeleteTask}
              onExecute={setExecuteTask}
              pendingTaskId={pendingTaskId}
            />
          )}
        </>
      )}

      {/* ── Tab: Tâches ── */}
      {tab === 'tasks' && (
        loadingTasks ? <PageSpinner /> : techTasks.length === 0 ? (
          <EmptyState
            icon={ListTodo}
            title="Aucune tâche technique"
            description="Les tâches techniques sont créées automatiquement lors de la planification d'une story, ou manuellement."
          />
        ) : (
          <div className="card divide-y" style={{ borderColor: 'var(--border)' }}>
            {techTasks.map((t) => <TechTaskRow key={t.id} task={t} onDelete={setDeleteTask} />)}
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
            action={<button className="btn-secondary" onClick={() => setTab('general')}><Settings className="w-4 h-4" /> Configurer</button>}
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
                  <div key={agent.id} className="card p-4 flex items-center gap-3">
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
                  </div>
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

      {/* ── Modals ── */}
      <Modal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title={tab === 'board' ? 'Nouvelle story / bug' : 'Nouvelle tâche technique'}
      >
        <TaskForm
          initial={{ type: tab === 'board' ? 'user_story' : 'task' }}
          onSubmit={(d) => createMutation.mutate(d)}
          loading={createMutation.isPending}
          onCancel={() => setCreateOpen(false)}
        />
      </Modal>

      {executeTask && (
        <Modal open onClose={() => setExecuteTask(null)} title="Lancer l'agent">
          <ExecuteModal
            task={executeTask}
            onClose={() => setExecuteTask(null)}
            onExecuted={() => invalidateTasks()}
          />
        </Modal>
      )}

      <ConfirmDialog
        open={!!deleteTask}
        onClose={() => setDeleteTask(null)}
        onConfirm={() => deleteTask && deleteMutation.mutate(deleteTask.id)}
        message={`Supprimer "${deleteTask?.title}" ? Cette action est irréversible.`}
        loading={deleteMutation.isPending}
      />
    </>
  )
}
