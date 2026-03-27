import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Plus, ListTodo, ChevronRight, CheckCircle, XCircle, Clock,
  AlertTriangle, GitBranch, User, ArrowRight, Layers,
} from 'lucide-react'
import { tasksApi } from '@/api/tasks'
import { projectsApi } from '@/api/projects'
import type { TaskPayload } from '@/api/tasks'
import type { Task, TaskStatus, TaskPriority, TaskType, StoryStatus } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'

// ─── Labels & styles ──────────────────────────────────────────────────────────

const TYPE_LABELS: Record<TaskType, string>   = { user_story: 'US', bug: 'Bug', task: 'Tâche' }
const TYPE_BADGE:  Record<TaskType, string>   = { user_story: 'badge-blue', bug: 'badge-red', task: 'badge-green' }

const PRIORITY_LABELS: Record<TaskPriority, string> = { low: 'Faible', medium: 'Normale', high: 'Haute', critical: 'Critique' }
const PRIORITY_COLOR:  Record<TaskPriority, string> = { low: 'text-gray-400', medium: 'text-blue-500', high: 'text-orange-500', critical: 'text-red-600' }

const STATUS_LABELS: Record<TaskStatus, string> = {
  backlog: 'Backlog', todo: 'À faire', in_progress: 'En cours',
  review: 'Revue', done: 'Terminé', cancelled: 'Annulé',
}

const STORY_COLUMNS: { key: StoryStatus; label: string; color: string; bg: string }[] = [
  { key: 'new',           label: 'Nouvelle',       color: 'text-gray-500',   bg: 'bg-gray-50'   },
  { key: 'ready',         label: 'Prête',           color: 'text-blue-500',   bg: 'bg-blue-50'   },
  { key: 'approved',      label: 'Approuvée',       color: 'text-indigo-500', bg: 'bg-indigo-50' },
  { key: 'planning',      label: 'Planification',   color: 'text-purple-500', bg: 'bg-purple-50' },
  { key: 'graphic_design',label: 'Conception',      color: 'text-pink-500',   bg: 'bg-pink-50'   },
  { key: 'development',   label: 'Développement',   color: 'text-orange-500', bg: 'bg-orange-50' },
  { key: 'code_review',   label: 'Revue de code',   color: 'text-yellow-600', bg: 'bg-yellow-50' },
  { key: 'done',          label: 'Terminée',        color: 'text-green-600',  bg: 'bg-green-50'  },
]

const STORY_TRANSITION_LABELS: Partial<Record<StoryStatus, string>> = {
  ready:         'Marquer prête',
  approved:      'Approuver',
  planning:      'Lancer planification',
  graphic_design:'Conception graphique',
  development:   'Développement',
  code_review:   'Revue de code',
  done:          'Terminer',
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function StatusIcon({ status }: { status: TaskStatus }) {
  if (status === 'done')       return <CheckCircle className="w-4 h-4 text-green-500" />
  if (status === 'cancelled')  return <XCircle className="w-4 h-4 text-gray-400" />
  if (status === 'in_progress' || status === 'review') return <Clock className="w-4 h-4 text-blue-500" />
  if (status === 'backlog')    return <AlertTriangle className="w-4 h-4 text-gray-300" />
  return <ChevronRight className="w-4 h-4 text-gray-400" />
}

function ProgressBar({ value }: { value: number }) {
  return (
    <div className="w-full bg-gray-100 rounded-full h-1">
      <div className="h-1 rounded-full" style={{ width: `${value}%`, background: 'var(--brand)' }} />
    </div>
  )
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
        <label className="block text-sm font-medium text-gray-700 mb-1">Titre *</label>
        <input className="input" value={title} onChange={(e) => setTitle(e.target.value)} required placeholder="En tant qu'utilisateur, je veux..." />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Type</label>
          <select className="input" value={type} onChange={(e) => setType(e.target.value as TaskType)}>
            <option value="user_story">User Story</option>
            <option value="bug">Bug</option>
            <option value="task">Tâche technique</option>
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Priorité</label>
          <select className="input" value={priority} onChange={(e) => setPriority(e.target.value as TaskPriority)}>
            <option value="low">Faible</option>
            <option value="medium">Normale</option>
            <option value="high">Haute</option>
            <option value="critical">Critique</option>
          </select>
        </div>
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea className="input resize-none" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Annuler</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? 'Enregistrement…' : 'Enregistrer'}</button>
      </div>
    </form>
  )
}

// ─── Story card (Kanban) ──────────────────────────────────────────────────────

function StoryCard({ task, onTransition, onDelete }: {
  task: Task
  onTransition: (task: Task, status: StoryStatus) => void
  onDelete: (task: Task) => void
}) {
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

      <p className="font-medium text-gray-900 leading-snug">{task.title}</p>

      {task.branchName && (
        <div className="flex items-center gap-1 text-xs text-gray-400">
          <GitBranch className="w-3 h-3" />
          <code className="font-mono truncate">{task.branchName}</code>
        </div>
      )}

      {task.assignedRole && (
        <div className="flex items-center gap-1 text-xs text-gray-400">
          <User className="w-3 h-3" />
          <span>{task.assignedRole.name}</span>
        </div>
      )}

      {task.progress > 0 && (
        <div className="space-y-0.5">
          <ProgressBar value={task.progress} />
          <span className="text-xs text-gray-400">{task.progress}%</span>
        </div>
      )}

      {task.storyStatusAllowedTransitions.length > 0 && (
        <div className="flex flex-wrap gap-1 pt-1 border-t border-gray-100">
          {task.storyStatusAllowedTransitions.map((next) => (
            <button
              key={next}
              onClick={() => onTransition(task, next)}
              className="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded border border-gray-200 hover:border-[var(--brand)] hover:text-[var(--brand)] transition-colors"
            >
              <ArrowRight className="w-2.5 h-2.5" />
              {STORY_TRANSITION_LABELS[next] ?? next}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

// ─── Story board (Kanban) ─────────────────────────────────────────────────────

function StoryBoard({ stories, onTransition, onDelete }: {
  stories: Task[]
  onTransition: (task: Task, status: StoryStatus) => void
  onDelete: (task: Task) => void
}) {
  if (stories.length === 0) {
    return (
      <EmptyState icon={Layers} title="Aucune story" description="Créez des user stories ou des bugs pour ce projet." />
    )
  }

  return (
    <div className="flex gap-3 overflow-x-auto pb-4">
      {STORY_COLUMNS.map((col) => {
        const cards = stories.filter((s) => s.storyStatus === col.key)
        return (
          <div key={col.key} className="flex-shrink-0 w-60">
            <div className={`flex items-center justify-between px-3 py-1.5 rounded-t-lg ${col.bg}`}>
              <span className={`text-xs font-semibold ${col.color}`}>{col.label}</span>
              {cards.length > 0 && (
                <span className={`text-xs font-medium ${col.color} opacity-60`}>{cards.length}</span>
              )}
            </div>
            <div className="space-y-2 min-h-16 bg-gray-50/40 rounded-b-lg p-2 border border-t-0 border-gray-100">
              {cards.map((task) => (
                <StoryCard key={task.id} task={task} onTransition={onTransition} onDelete={onDelete} />
              ))}
              {cards.length === 0 && (
                <p className="text-xs text-gray-300 text-center py-3">—</p>
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}

// ─── Task list row ────────────────────────────────────────────────────────────

function TaskRow({ task, onDelete }: {
  task: Task
  onDelete: (t: Task) => void
}) {
  return (
    <div className="px-4 py-3 flex items-center gap-3">
      <StatusIcon status={task.status} />
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className={`${TYPE_BADGE[task.type]} text-xs`}>{TYPE_LABELS[task.type]}</span>
          <p className="text-sm font-medium text-gray-900 truncate">{task.title}</p>
        </div>
        <div className="flex items-center gap-3 mt-0.5 flex-wrap">
          <span className="text-xs text-gray-500">{STATUS_LABELS[task.status]}</span>
          <span className={`text-xs font-medium ${PRIORITY_COLOR[task.priority]}`}>{PRIORITY_LABELS[task.priority]}</span>
          {task.assignedAgent && <span className="text-xs text-gray-400">→ {task.assignedAgent.name}</span>}
          {task.assignedRole  && <span className="text-xs text-gray-400 italic">({task.assignedRole.name})</span>}
          {task.parent        && <span className="text-xs text-gray-300">↑ {task.parent.title}</span>}
        </div>
        {task.progress > 0 && (
          <div className="mt-1.5 flex items-center gap-2">
            <ProgressBar value={task.progress} />
            <span className="text-xs text-gray-400 whitespace-nowrap">{task.progress}%</span>
          </div>
        )}
      </div>
      <button onClick={() => onDelete(task)} className="p-1.5 text-gray-400 hover:text-red-500" title="Supprimer">
        <XCircle className="w-3.5 h-3.5" />
      </button>
    </div>
  )
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function TasksPage() {
  const qc = useQueryClient()
  const [projectId, setProjectId]   = useState('')
  const [tab, setTab]               = useState<'stories' | 'tasks'>('stories')
  const [createOpen, setCreateOpen] = useState(false)
  const [deleteTask, setDeleteTask] = useState<Task | null>(null)

  const { data: projects } = useQuery({ queryKey: ['projects'], queryFn: projectsApi.list })
  const { data: tasks, isLoading, error, refetch } = useQuery({
    queryKey: ['tasks', projectId],
    queryFn:  () => tasksApi.listByProject(projectId),
    enabled:  !!projectId,
  })

  const invalidate = () => qc.invalidateQueries({ queryKey: ['tasks', projectId] })

  const createMutation     = useMutation({ mutationFn: (d: TaskPayload) => tasksApi.create(projectId, d), onSuccess: () => { invalidate(); setCreateOpen(false) } })
  const transitionMutation = useMutation({
    mutationFn: ({ id, status }: { id: string; status: StoryStatus }) => tasksApi.transitionStory(id, status),
    onSuccess: invalidate,
  })
  const deleteMutation = useMutation({ mutationFn: (id: string) => tasksApi.delete(id), onSuccess: () => { invalidate(); setDeleteTask(null) } })

  const stories   = tasks?.filter((t) => t.type === 'user_story' || t.type === 'bug') ?? []
  const techTasks = tasks?.filter((t) => t.type === 'task') ?? []

  return (
    <>
      <PageHeader
        title="Tâches"
        description="Stories, bugs et tâches techniques du projet."
        action={projectId ? (
          <button className="btn-primary" onClick={() => setCreateOpen(true)}>
            <Plus className="w-4 h-4" /> Nouvelle tâche
          </button>
        ) : undefined}
      />

      <div className="mb-6">
        <label className="block text-sm font-medium text-gray-700 mb-1">Projet</label>
        <select className="input max-w-sm" value={projectId} onChange={(e) => setProjectId(e.target.value)}>
          <option value="">— Sélectionner un projet —</option>
          {projects?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
      </div>

      {!projectId ? null : isLoading ? <PageSpinner /> : error ? (
        <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />
      ) : (
        <>
          <div className="flex gap-1 mb-4 border-b border-gray-200">
            <button
              onClick={() => setTab('stories')}
              className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${tab === 'stories' ? 'border-[var(--brand)] text-[var(--brand)]' : 'border-transparent text-gray-500 hover:text-gray-700'}`}
            >
              Stories &amp; Bugs
              {stories.length > 0 && <span className="ml-1.5 text-xs opacity-60">{stories.length}</span>}
            </button>
            <button
              onClick={() => setTab('tasks')}
              className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${tab === 'tasks' ? 'border-[var(--brand)] text-[var(--brand)]' : 'border-transparent text-gray-500 hover:text-gray-700'}`}
            >
              Tâches techniques
              {techTasks.length > 0 && <span className="ml-1.5 text-xs opacity-60">{techTasks.length}</span>}
            </button>
          </div>

          {tab === 'stories' && (
            <StoryBoard
              stories={stories}
              onTransition={(task, status) => transitionMutation.mutate({ id: task.id, status })}
              onDelete={setDeleteTask}
            />
          )}

          {tab === 'tasks' && (
            techTasks.length === 0 ? (
              <EmptyState
                icon={ListTodo}
                title="Aucune tâche technique"
                description="Les tâches techniques sont créées automatiquement lors de la planification d'une story."
              />
            ) : (
              <div className="card divide-y divide-gray-100">
                {techTasks.map((t) => (
                  <TaskRow key={t.id} task={t} onDelete={setDeleteTask} />
                ))}
              </div>
            )
          )}
        </>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Nouvelle tâche">
        <TaskForm
          onSubmit={(d) => createMutation.mutate(d)}
          loading={createMutation.isPending}
          onCancel={() => setCreateOpen(false)}
        />
      </Modal>

      <ConfirmDialog
        open={!!deleteTask}
        onClose={() => setDeleteTask(null)}
        onConfirm={() => deleteTask && deleteMutation.mutate(deleteTask.id)}
        message={`Supprimer "${deleteTask?.title}" ?`}
        loading={deleteMutation.isPending}
      />
    </>
  )
}
