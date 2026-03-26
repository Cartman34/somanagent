import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft, Plus, Layers, ListTodo, Code2, Globe,
  ChevronDown, ChevronRight, Trash2, MessageSquarePlus,
} from 'lucide-react'
import { projectsApi } from '@/api/projects'
import { featuresApi } from '@/api/features'
import { tasksApi } from '@/api/tasks'
import type { FeaturePayload } from '@/api/features'
import type { TaskPayload } from '@/api/tasks'
import type { Feature, Task, TaskStatus, TaskType, TaskPriority, Module } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'

// ─── Constants ────────────────────────────────────────────────────────────────

const TYPE_BADGE: Record<TaskType, string>      = { user_story: 'badge-blue', bug: 'badge-red', task: 'badge-green' }
const TYPE_LABELS: Record<TaskType, string>     = { user_story: 'US', bug: 'Bug', task: 'Tâche' }
const STATUS_LABELS: Record<TaskStatus, string> = { backlog: 'Backlog', todo: 'À faire', in_progress: 'En cours', review: 'Revue', done: 'Terminé', cancelled: 'Annulé' }
const STATUS_BADGE: Record<TaskStatus, string>  = { backlog: 'badge-gray', todo: 'badge-blue', in_progress: 'badge-orange', review: 'badge-orange', done: 'badge-green', cancelled: 'badge-gray' }
const FEATURE_STATUS_LABELS: Record<string, string> = { open: 'Ouverte', in_progress: 'En cours', closed: 'Fermée' }
const FEATURE_STATUS_BADGE: Record<string, string>  = { open: 'badge-blue', in_progress: 'badge-orange', closed: 'badge-green' }
const PRIORITY_LABELS: Record<string, string> = { low: 'Faible', medium: 'Normale', high: 'Haute', critical: 'Critique' }
const PRIORITY_COLOR: Record<string, string>  = { low: 'text-gray-400', medium: 'text-blue-500', high: 'text-orange-500', critical: 'text-red-600' }

// ─── Task Row ─────────────────────────────────────────────────────────────────

function TaskRow({ task, onDelete }: { task: Task; onDelete: (t: Task) => void }) {
  return (
    <div className="flex items-center gap-3 py-2 px-3 rounded" style={{ background: 'var(--surface2)' }}>
      <span className={`${TYPE_BADGE[task.type]} text-xs flex-shrink-0`}>{TYPE_LABELS[task.type]}</span>
      <span className="flex-1 text-sm truncate" style={{ color: 'var(--text)' }}>{task.title}</span>
      <span className={`text-xs flex-shrink-0 ${PRIORITY_COLOR[task.priority]}`}>{PRIORITY_LABELS[task.priority]}</span>
      <span className={`${STATUS_BADGE[task.status]} text-xs flex-shrink-0`}>{STATUS_LABELS[task.status]}</span>
      {task.assignedAgent && (
        <span className="text-xs flex-shrink-0 hidden sm:inline" style={{ color: 'var(--muted)' }}>
          → {task.assignedAgent.name}
        </span>
      )}
      <button
        onClick={() => onDelete(task)}
        className="p-1 flex-shrink-0"
        style={{ color: 'var(--muted)' }}
        title="Supprimer"
      >
        <Trash2 className="w-3 h-3" />
      </button>
    </div>
  )
}

// ─── Feature Section ──────────────────────────────────────────────────────────

function FeatureSection({
  feature,
  userStories,
  onAddUS,
  onDeleteTask,
  onDeleteFeature,
}: {
  feature: Feature
  userStories: Task[]
  onAddUS: (featureId: string) => void
  onDeleteTask: (t: Task) => void
  onDeleteFeature: (f: Feature) => void
}) {
  const [open, setOpen] = useState(true)

  return (
    <div className="card overflow-hidden">
      <div
        className="flex items-center gap-3 px-4 py-3 cursor-pointer select-none"
        style={{ borderBottom: open ? '1px solid var(--border)' : 'none' }}
        onClick={() => setOpen(!open)}
      >
        {open
          ? <ChevronDown className="w-4 h-4 flex-shrink-0" style={{ color: 'var(--muted)' }} />
          : <ChevronRight className="w-4 h-4 flex-shrink-0" style={{ color: 'var(--muted)' }} />}
        <span className="font-semibold text-sm flex-1" style={{ color: 'var(--text)' }}>{feature.name}</span>
        <span className={`${FEATURE_STATUS_BADGE[feature.status]} text-xs`}>{FEATURE_STATUS_LABELS[feature.status]}</span>
        <span className="text-xs ml-2" style={{ color: 'var(--muted)' }}>{userStories.length} US</span>
        <button
          onClick={(e) => { e.stopPropagation(); onDeleteFeature(feature) }}
          className="p-1 ml-1"
          style={{ color: 'var(--muted)' }}
          title="Supprimer la feature"
        >
          <Trash2 className="w-3.5 h-3.5" />
        </button>
      </div>

      {open && (
        <div className="p-3 space-y-2">
          {feature.description && (
            <p className="text-xs px-1 pb-1" style={{ color: 'var(--muted)' }}>{feature.description}</p>
          )}
          {userStories.length === 0 ? (
            <p className="text-xs px-1 py-2 text-center" style={{ color: 'var(--muted)' }}>
              Aucune user story — ajoutez-en une ci-dessous.
            </p>
          ) : (
            userStories.map((us) => <TaskRow key={us.id} task={us} onDelete={onDeleteTask} />)
          )}
          <button
            onClick={() => onAddUS(feature.id)}
            className="flex items-center gap-1.5 text-xs mt-1 px-3 py-2 rounded w-full transition-colors"
            style={{ color: 'var(--brand)', background: 'var(--brand-dim)' }}
          >
            <Plus className="w-3.5 h-3.5" /> Ajouter une user story
          </button>
        </div>
      )}
    </div>
  )
}

// ─── Demand Form (guided, 3 steps) ────────────────────────────────────────────

function DemandForm({
  onSubmit,
  loading,
  onCancel,
}: {
  onSubmit: (title: string, description: string, context: string) => void
  loading: boolean
  onCancel: () => void
}) {
  const [step, setStep] = useState(1)
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [context, setContext] = useState('')

  return (
    <div className="space-y-5">
      {/* Progress bar */}
      <div className="flex gap-1.5">
        {[1, 2, 3].map((s) => (
          <div
            key={s}
            className="flex-1 h-1 rounded-full transition-colors"
            style={{ background: s <= step ? 'var(--brand)' : 'var(--border)' }}
          />
        ))}
      </div>

      {step === 1 && (
        <div>
          <p className="text-sm font-medium mb-3" style={{ color: 'var(--text)' }}>
            Quel est votre besoin en quelques mots ?
          </p>
          <input
            className="input"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder="Ex : Permettre aux utilisateurs de se connecter via Google"
            autoFocus
          />
        </div>
      )}

      {step === 2 && (
        <div>
          <p className="text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>
            Décrivez l'objectif et le résultat attendu.
          </p>
          <p className="text-xs mb-3" style={{ color: 'var(--muted)' }}>
            Expliquez pourquoi c'est nécessaire et ce que vous souhaitez obtenir.
          </p>
          <textarea
            className="input resize-none"
            rows={4}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="Actuellement, les utilisateurs doivent créer un compte manuellement…"
            autoFocus
          />
        </div>
      )}

      {step === 3 && (
        <div>
          <p className="text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>
            Contexte et utilisateurs concernés
          </p>
          <p className="text-xs mb-3" style={{ color: 'var(--muted)' }}>
            Qui sont les utilisateurs concernés ? Y a-t-il des contraintes particulières ?
          </p>
          <textarea
            className="input resize-none"
            rows={3}
            value={context}
            onChange={(e) => setContext(e.target.value)}
            placeholder="Ex : Utilisateurs finaux grand public, pas d'accès admin requis…"
            autoFocus
          />
          <p className="text-xs mt-3 p-3 rounded-lg" style={{ background: 'var(--brand-dim)', color: 'var(--brand)' }}>
            Cette demande sera transmise à l'agent PO pour reformulation en user stories et features.
          </p>
        </div>
      )}

      <div className="flex justify-between gap-3 pt-1">
        <button type="button" onClick={onCancel} className="btn-secondary">Annuler</button>
        <div className="flex gap-2">
          {step > 1 && (
            <button type="button" onClick={() => setStep((s) => s - 1)} className="btn-secondary">
              ← Retour
            </button>
          )}
          {step < 3 ? (
            <button
              type="button"
              onClick={() => setStep((s) => s + 1)}
              className="btn-primary"
              disabled={step === 1 && !title.trim()}
            >
              Suivant →
            </button>
          ) : (
            <button
              type="button"
              onClick={() => onSubmit(title, description, context)}
              className="btn-primary"
              disabled={loading || !title.trim()}
            >
              {loading ? 'Envoi…' : 'Soumettre au PO →'}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}

// ─── Task Form ────────────────────────────────────────────────────────────────

function TaskForm({
  featureId,
  defaultType = 'user_story',
  onSubmit,
  loading,
  onCancel,
}: {
  featureId?: string
  defaultType?: TaskType
  onSubmit: (d: TaskPayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const [title, setTitle]       = useState('')
  const [type, setType]         = useState<TaskType>(defaultType)
  const [priority, setPriority] = useState<TaskPriority>('medium')

  return (
    <form
      onSubmit={(e) => { e.preventDefault(); onSubmit({ title, type, priority, featureId }) }}
      className="space-y-3"
    >
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Titre *</label>
        <input className="input" value={title} onChange={(e) => setTitle(e.target.value)} required autoFocus />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Type</label>
          <select className="input" value={type} onChange={(e) => setType(e.target.value as TaskType)}>
            <option value="user_story">User Story</option>
            <option value="task">Tâche</option>
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
      <div className="flex justify-end gap-3 pt-1">
        <button type="button" onClick={onCancel} className="btn-secondary">Annuler</button>
        <button type="submit" className="btn-primary" disabled={loading}>
          {loading ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </div>
    </form>
  )
}

// ─── Feature Form ─────────────────────────────────────────────────────────────

function FeatureForm({
  onSubmit,
  loading,
  onCancel,
}: {
  onSubmit: (d: FeaturePayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const [name, setName]               = useState('')
  const [description, setDescription] = useState('')

  return (
    <form
      onSubmit={(e) => { e.preventDefault(); onSubmit({ name, description: description || undefined, status: 'open' }) }}
      className="space-y-3"
    >
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
        <input
          className="input"
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
          autoFocus
          placeholder="Ex : Authentification, Dashboard…"
        />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea
          className="input resize-none"
          rows={2}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
        />
      </div>
      <div className="flex justify-end gap-3 pt-1">
        <button type="button" onClick={onCancel} className="btn-secondary">Annuler</button>
        <button type="submit" className="btn-primary" disabled={loading}>
          {loading ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </div>
    </form>
  )
}

// ─── Main Page ────────────────────────────────────────────────────────────────

type Tab = 'features' | 'tasks' | 'modules'

export default function ProjectDetailPage() {
  const { id } = useParams<{ id: string }>()
  const qc = useQueryClient()

  const [tab, setTab]                       = useState<Tab>('features')
  const [demandOpen, setDemandOpen]         = useState(false)
  const [addFeatureOpen, setAddFeatureOpen] = useState(false)
  const [addTaskModal, setAddTaskModal]     = useState<{ featureId?: string; type: TaskType } | null>(null)
  const [deleteTaskTarget, setDeleteTaskTarget]       = useState<Task | null>(null)
  const [deleteFeatureTarget, setDeleteFeatureTarget] = useState<Feature | null>(null)

  const {
    data: project,
    isLoading: loadingProject,
    error: errorProject,
    refetch: refetchProject,
  } = useQuery({
    queryKey: ['projects', id],
    queryFn: () => projectsApi.get(id!),
    enabled: !!id,
  })

  const { data: features = [], isLoading: loadingFeatures } = useQuery({
    queryKey: ['features', id],
    queryFn: () => featuresApi.listByProject(id!),
    enabled: !!id,
  })

  const { data: tasks = [], isLoading: loadingTasks } = useQuery({
    queryKey: ['tasks', id],
    queryFn: () => tasksApi.listByProject(id!),
    enabled: !!id,
  })

  const createFeatureMutation = useMutation({
    mutationFn: (d: FeaturePayload) => featuresApi.create(id!, d),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['features', id] }); setAddFeatureOpen(false) },
  })

  const createTaskMutation = useMutation({
    mutationFn: (d: TaskPayload) => tasksApi.create(id!, d),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['tasks', id] }); setAddTaskModal(null) },
  })

  const deleteTaskMutation = useMutation({
    mutationFn: (tid: string) => tasksApi.delete(tid),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['tasks', id] }); setDeleteTaskTarget(null) },
  })

  const deleteFeatureMutation = useMutation({
    mutationFn: (fid: string) => featuresApi.delete(fid),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['features', id] })
      qc.invalidateQueries({ queryKey: ['tasks', id] })
      setDeleteFeatureTarget(null)
    },
  })

  const createDemandMutation = useMutation({
    mutationFn: (d: TaskPayload) => tasksApi.create(id!, d),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['tasks', id] }); setDemandOpen(false) },
  })

  if (loadingProject) return <PageSpinner />
  if (errorProject || !project) {
    return <ErrorMessage message={(errorProject as Error)?.message ?? 'Projet introuvable'} onRetry={() => refetchProject()} />
  }

  // Derived data
  const userStories  = tasks.filter((t) => t.type === 'user_story')
  const otherTasks   = tasks.filter((t) => t.type !== 'user_story')
  const modules      = Array.isArray(project.modules) ? (project.modules as Module[]) : []
  const doneTasks    = tasks.filter((t) => t.status === 'done').length
  const progress     = tasks.length > 0 ? Math.round((doneTasks / tasks.length) * 100) : 0

  const usByFeature: Record<string, Task[]> = {}
  const unassignedUS: Task[] = []
  for (const us of userStories) {
    if (us.feature) {
      usByFeature[us.feature.id] = [...(usByFeature[us.feature.id] ?? []), us]
    } else {
      unassignedUS.push(us)
    }
  }

  const tabs: { key: Tab; label: string; count: number }[] = [
    { key: 'features', label: 'Features & User Stories', count: features.length },
    { key: 'tasks',    label: 'Tâches',                  count: otherTasks.length },
    { key: 'modules',  label: 'Modules',                 count: modules.length },
  ]

  return (
    <>
      <Link to="/projects" className="inline-flex items-center gap-1 text-sm mb-4" style={{ color: 'var(--muted)' }}>
        <ArrowLeft className="w-4 h-4" /> Projets
      </Link>

      <PageHeader
        title={project.name}
        description={project.description ?? undefined}
        action={
          <button className="btn-primary" onClick={() => setDemandOpen(true)}>
            <MessageSquarePlus className="w-4 h-4" /> Nouvelle demande
          </button>
        }
      />

      {/* Stats */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        {[
          { label: 'Features',      value: features.length },
          { label: 'User Stories',  value: userStories.length },
          { label: 'Tâches',        value: otherTasks.length },
          { label: 'Progression',   value: `${progress} %` },
        ].map(({ label, value }) => (
          <div key={label} className="card p-3 text-center">
            <p className="text-xl font-bold" style={{ color: 'var(--text)' }}>{value}</p>
            <p className="text-xs mt-0.5" style={{ color: 'var(--muted)' }}>{label}</p>
          </div>
        ))}
      </div>

      {/* Tabs */}
      <div className="flex gap-0 mb-5 border-b" style={{ borderColor: 'var(--border)' }}>
        {tabs.map(({ key, label, count }) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className="px-4 py-2.5 text-sm font-medium transition-colors -mb-px border-b-2"
            style={{
              borderColor: tab === key ? 'var(--brand)' : 'transparent',
              color: tab === key ? 'var(--brand)' : 'var(--muted)',
            }}
          >
            {label}
            <span
              className="ml-1.5 text-xs px-1.5 py-0.5 rounded-full"
              style={{ background: 'var(--surface2)', color: 'var(--muted)' }}
            >
              {count}
            </span>
          </button>
        ))}
      </div>

      {/* ── Tab: Features & User Stories ── */}
      {tab === 'features' && (
        <div className="space-y-4">
          {loadingFeatures || loadingTasks ? <PageSpinner /> : (
            features.length === 0 && unassignedUS.length === 0 ? (
              <EmptyState
                icon={Layers}
                title="Aucune feature"
                description="Créez des features pour organiser vos user stories."
                action={
                  <button className="btn-primary" onClick={() => setAddFeatureOpen(true)}>
                    <Plus className="w-4 h-4" /> Nouvelle feature
                  </button>
                }
              />
            ) : (
              <>
                {features.map((feature) => (
                  <FeatureSection
                    key={feature.id}
                    feature={feature}
                    userStories={usByFeature[feature.id] ?? []}
                    onAddUS={(fid) => setAddTaskModal({ featureId: fid, type: 'user_story' })}
                    onDeleteTask={setDeleteTaskTarget}
                    onDeleteFeature={setDeleteFeatureTarget}
                  />
                ))}

                {unassignedUS.length > 0 && (
                  <div className="card overflow-hidden">
                    <div className="px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
                      <span className="text-sm font-semibold" style={{ color: 'var(--muted)' }}>
                        User stories sans feature ({unassignedUS.length})
                      </span>
                    </div>
                    <div className="p-3 space-y-2">
                      {unassignedUS.map((us) => <TaskRow key={us.id} task={us} onDelete={setDeleteTaskTarget} />)}
                    </div>
                  </div>
                )}
              </>
            )
          )}

          <button
            className="flex items-center gap-2 text-sm px-4 py-2 rounded-lg transition-colors"
            style={{ color: 'var(--brand)', background: 'var(--brand-dim)' }}
            onClick={() => setAddFeatureOpen(true)}
          >
            <Plus className="w-4 h-4" /> Nouvelle feature
          </button>
        </div>
      )}

      {/* ── Tab: Tâches ── */}
      {tab === 'tasks' && (
        <div className="space-y-4">
          {loadingTasks ? <PageSpinner /> : otherTasks.length === 0 ? (
            <EmptyState
              icon={ListTodo}
              title="Aucune tâche"
              description="Créez des tâches et des bugs pour ce projet."
              action={
                <button className="btn-primary" onClick={() => setAddTaskModal({ type: 'task' })}>
                  <Plus className="w-4 h-4" /> Nouvelle tâche
                </button>
              }
            />
          ) : (
            <div className="card divide-y" style={{ borderColor: 'var(--border)' }}>
              {otherTasks.map((task) => (
                <div key={task.id} className="px-4 py-2.5">
                  <TaskRow task={task} onDelete={setDeleteTaskTarget} />
                </div>
              ))}
            </div>
          )}

          <button
            className="flex items-center gap-2 text-sm px-4 py-2 rounded-lg transition-colors"
            style={{ color: 'var(--brand)', background: 'var(--brand-dim)' }}
            onClick={() => setAddTaskModal({ type: 'task' })}
          >
            <Plus className="w-4 h-4" /> Nouvelle tâche
          </button>
        </div>
      )}

      {/* ── Tab: Modules ── */}
      {tab === 'modules' && (
        <div>
          {modules.length === 0 ? (
            <EmptyState
              icon={Code2}
              title="Aucun module"
              description="Les modules représentent les composants logiciels du projet (API, client mobile, etc.)."
            />
          ) : (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {modules.map((mod) => (
                <div key={mod.id} className="card p-4 flex flex-col gap-2">
                  <p className="font-medium text-sm" style={{ color: 'var(--text)' }}>{mod.name}</p>
                  {mod.description && <p className="text-xs" style={{ color: 'var(--muted)' }}>{mod.description}</p>}
                  {mod.stack && <span className="badge-blue self-start">{mod.stack}</span>}
                  {mod.repositoryUrl && (
                    <a
                      href={mod.repositoryUrl}
                      target="_blank"
                      rel="noreferrer"
                      className="inline-flex items-center gap-1 text-xs"
                      style={{ color: 'var(--brand)' }}
                    >
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
          )}
        </div>
      )}

      {/* ── Modals ── */}
      <Modal open={addFeatureOpen} onClose={() => setAddFeatureOpen(false)} title="Nouvelle feature">
        <FeatureForm
          onSubmit={(d) => createFeatureMutation.mutate(d)}
          loading={createFeatureMutation.isPending}
          onCancel={() => setAddFeatureOpen(false)}
        />
      </Modal>

      <Modal
        open={!!addTaskModal}
        onClose={() => setAddTaskModal(null)}
        title={addTaskModal?.type === 'user_story' ? 'Nouvelle user story' : 'Nouvelle tâche'}
      >
        {addTaskModal && (
          <TaskForm
            featureId={addTaskModal.featureId}
            defaultType={addTaskModal.type}
            onSubmit={(d) => createTaskMutation.mutate(d)}
            loading={createTaskMutation.isPending}
            onCancel={() => setAddTaskModal(null)}
          />
        )}
      </Modal>

      <Modal open={demandOpen} onClose={() => setDemandOpen(false)} title="Nouvelle demande">
        <DemandForm
          onSubmit={(title, description, context) => {
            const fullDesc = [description, context ? `Contexte : ${context}` : '']
              .filter(Boolean)
              .join('\n\n')
            createDemandMutation.mutate({
              title: `[Demande] ${title}`,
              description: fullDesc || undefined,
              type: 'task',
              priority: 'high',
            })
          }}
          loading={createDemandMutation.isPending}
          onCancel={() => setDemandOpen(false)}
        />
      </Modal>

      <ConfirmDialog
        open={!!deleteTaskTarget}
        onClose={() => setDeleteTaskTarget(null)}
        onConfirm={() => deleteTaskTarget && deleteTaskMutation.mutate(deleteTaskTarget.id)}
        message={`Supprimer "${deleteTaskTarget?.title}" ? Cette action est irréversible.`}
        loading={deleteTaskMutation.isPending}
      />

      <ConfirmDialog
        open={!!deleteFeatureTarget}
        onClose={() => setDeleteFeatureTarget(null)}
        onConfirm={() => deleteFeatureTarget && deleteFeatureMutation.mutate(deleteFeatureTarget.id)}
        message={`Supprimer la feature "${deleteFeatureTarget?.name}" ? Cette action est irréversible.`}
        loading={deleteFeatureMutation.isPending}
      />
    </>
  )
}
