import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, ListTodo, ChevronRight, CheckCircle, XCircle, Clock, AlertTriangle } from 'lucide-react'
import { tasksApi } from '@/api/tasks'
import { projectsApi } from '@/api/projects'
import type { TaskPayload } from '@/api/tasks'
import type { Task, TaskStatus, TaskPriority, TaskType } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'

const TYPE_LABELS: Record<TaskType, string>         = { user_story: 'US', bug: 'Bug', task: 'Tâche' }
const TYPE_BADGE: Record<TaskType, string>          = { user_story: 'badge-blue', bug: 'badge-red', task: 'badge-green' }
const STATUS_LABELS: Record<TaskStatus, string>     = { backlog: 'Backlog', todo: 'À faire', in_progress: 'En cours', review: 'Revue', done: 'Terminé', cancelled: 'Annulé' }
const PRIORITY_LABELS: Record<TaskPriority, string> = { low: 'Faible', medium: 'Normale', high: 'Haute', critical: 'Critique' }
const PRIORITY_COLOR: Record<TaskPriority, string>  = { low: 'text-gray-400', medium: 'text-blue-500', high: 'text-orange-500', critical: 'text-red-600' }

function StatusIcon({ status }: { status: TaskStatus }) {
  if (status === 'done')       return <CheckCircle className="w-4 h-4 text-green-500" />
  if (status === 'cancelled')  return <XCircle className="w-4 h-4 text-gray-400" />
  if (status === 'in_progress' || status === 'review') return <Clock className="w-4 h-4 text-blue-500" />
  if (status === 'backlog')    return <AlertTriangle className="w-4 h-4 text-gray-300" />
  return <ChevronRight className="w-4 h-4 text-gray-400" />
}

function ProgressBar({ value }: { value: number }) {
  return (
    <div className="w-full bg-gray-100 rounded-full h-1.5">
      <div className="h-1.5 rounded-full transition-all" style={{ width: `${value}%`, background: value === 100 ? 'var(--brand)' : 'var(--brand)' }} />
    </div>
  )
}

function TaskForm({ initial, projectId, onSubmit, loading, onCancel }: {
  initial?: Partial<TaskPayload>
  projectId: string
  onSubmit: (d: TaskPayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const [title, setTitle]             = useState(initial?.title ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [type, setType]               = useState<TaskType>(initial?.type ?? 'task')
  const [priority, setPriority]       = useState<TaskPriority>(initial?.priority ?? 'medium')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ title, description: description || undefined, type, priority }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Titre *</label>
        <input className="input" value={title} onChange={(e) => setTitle(e.target.value)} required placeholder="Implémenter la connexion OAuth" />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Type</label>
          <select className="input" value={type} onChange={(e) => setType(e.target.value as TaskType)}>
            <option value="task">Tâche</option>
            <option value="user_story">User Story</option>
            <option value="bug">Bug</option>
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

function TaskRow({ task, onValidate, onReject, onDelete, projectId }: {
  task: Task
  onValidate: (t: Task) => void
  onReject: (t: Task) => void
  onDelete: (t: Task) => void
  projectId: string
}) {
  return (
    <div className="px-4 py-3 flex items-center gap-3">
      <StatusIcon status={task.status} />
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className={`${TYPE_BADGE[task.type]} text-xs`}>{TYPE_LABELS[task.type]}</span>
          <p className="text-sm font-medium text-gray-900 truncate">{task.title}</p>
        </div>
        <div className="flex items-center gap-3 mt-1">
          <span className="text-xs text-gray-500">{STATUS_LABELS[task.status]}</span>
          <span className={`text-xs font-medium ${PRIORITY_COLOR[task.priority]}`}>{PRIORITY_LABELS[task.priority]}</span>
          {task.assignedAgent && <span className="text-xs text-gray-400">→ {task.assignedAgent.name}</span>}
        </div>
        <div className="mt-1.5 flex items-center gap-2">
          <ProgressBar value={task.progress} />
          <span className="text-xs text-gray-400 whitespace-nowrap">{task.progress}%</span>
        </div>
      </div>
      <div className="flex gap-1 flex-shrink-0">
        {task.status === 'review' && (
          <>
            <button onClick={() => onValidate(task)} className="btn-primary text-xs px-2 py-1">Valider</button>
            <button onClick={() => onReject(task)} className="btn-secondary text-xs px-2 py-1">Rejeter</button>
          </>
        )}
        <button onClick={() => onDelete(task)} className="p-1.5 text-gray-400 hover:text-red-500" title="Supprimer">
          <XCircle className="w-3.5 h-3.5" />
        </button>
      </div>
    </div>
  )
}

export default function TasksPage() {
  const qc = useQueryClient()
  const [projectId, setProjectId]     = useState('')
  const [createOpen, setCreateOpen]   = useState(false)
  const [deleteTask, setDeleteTask]   = useState<Task | null>(null)

  const { data: projects } = useQuery({ queryKey: ['projects'], queryFn: projectsApi.list })
  const { data: tasks, isLoading, error, refetch } = useQuery({
    queryKey: ['tasks', projectId],
    queryFn: () => tasksApi.listByProject(projectId),
    enabled: !!projectId,
  })

  const createMutation   = useMutation({ mutationFn: (d: TaskPayload) => tasksApi.create(projectId, d), onSuccess: () => { qc.invalidateQueries({ queryKey: ['tasks', projectId] }); setCreateOpen(false) } })
  const validateMutation = useMutation({ mutationFn: (id: string) => tasksApi.validate(id), onSuccess: () => qc.invalidateQueries({ queryKey: ['tasks', projectId] }) })
  const rejectMutation   = useMutation({ mutationFn: (id: string) => tasksApi.reject(id), onSuccess: () => qc.invalidateQueries({ queryKey: ['tasks', projectId] }) })
  const deleteMutation   = useMutation({ mutationFn: (id: string) => tasksApi.delete(id), onSuccess: () => { qc.invalidateQueries({ queryKey: ['tasks', projectId] }); setDeleteTask(null) } })

  const reviewTasks = tasks?.filter((t) => t.status === 'review') ?? []
  const otherTasks  = tasks?.filter((t) => t.status !== 'review') ?? []

  return (
    <>
      <PageHeader title="Tâches" description="Suivez l'avancement des tâches, user stories et bugs."
        action={projectId ? <button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> Nouvelle tâche</button> : undefined} />

      <div className="mb-6">
        <label className="block text-sm font-medium text-gray-700 mb-1">Projet</label>
        <select className="input max-w-sm" value={projectId} onChange={(e) => setProjectId(e.target.value)}>
          <option value="">— Sélectionner un projet —</option>
          {projects?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
      </div>

      {!projectId ? null : isLoading ? <PageSpinner /> : error ? (
        <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />
      ) : tasks?.length === 0 ? (
        <EmptyState icon={ListTodo} title="Aucune tâche" description="Créez des tâches, user stories ou bugs pour ce projet."
          action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> Nouvelle tâche</button>} />
      ) : (
        <div className="space-y-6">
          {reviewTasks.length > 0 && (
            <div>
              <h2 className="text-sm font-semibold text-orange-600 mb-2">En attente de validation ({reviewTasks.length})</h2>
              <div className="card divide-y divide-gray-100 border-l-4 border-orange-400">
                {reviewTasks.map((t) => (
                  <TaskRow key={t.id} task={t} projectId={projectId}
                    onValidate={(task) => validateMutation.mutate(task.id)}
                    onReject={(task) => rejectMutation.mutate(task.id)}
                    onDelete={setDeleteTask} />
                ))}
              </div>
            </div>
          )}
          <div>
            <h2 className="text-sm font-semibold text-gray-600 mb-2">Toutes les tâches ({otherTasks.length})</h2>
            <div className="card divide-y divide-gray-100">
              {otherTasks.map((t) => (
                <TaskRow key={t.id} task={t} projectId={projectId}
                  onValidate={(task) => validateMutation.mutate(task.id)}
                  onReject={(task) => rejectMutation.mutate(task.id)}
                  onDelete={setDeleteTask} />
              ))}
            </div>
          </div>
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Nouvelle tâche">
        <TaskForm projectId={projectId} onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} />
      </Modal>

      <ConfirmDialog open={!!deleteTask} onClose={() => setDeleteTask(null)}
        onConfirm={() => deleteTask && deleteMutation.mutate(deleteTask.id)}
        message={`Supprimer la tâche "${deleteTask?.title}" ?`}
        loading={deleteMutation.isPending} />
    </>
  )
}
