import { useState, type ReactNode } from 'react'
import { Routes, Route, useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Plus, GitBranch, ArrowLeft, Pencil, Trash2, CheckCircle, XCircle,
  Clock, Play, SkipForward, AlertCircle, Lock, ShieldCheck, FileEdit,
  ChevronRight, User, BookOpen, AlertTriangle,
} from 'lucide-react'
import { workflowsApi } from '@/api/workflows'
import type { WorkflowPayload } from '@/api/workflows'
import type { Workflow, WorkflowStep, WorkflowStatus } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'

// ─── Constants ────────────────────────────────────────────────────────────────

const TRIGGER_LABELS: Record<string, string> = {
  manual: 'Manuel',
  vcs_event: 'Événement VCS',
  scheduled: 'Planifié',
}

const TRIGGER_COLORS: Record<string, string> = {
  manual: 'badge-blue',
  vcs_event: 'badge-orange',
  scheduled: 'badge-gray',
}

/** Ordered story lifecycle stages displayed in the visual pipeline. */
const LIFECYCLE_STAGES: { key: string; label: string }[] = [
  { key: 'new',           label: 'Nouveau'      },
  { key: 'ready',         label: 'Prêt'         },
  { key: 'approved',      label: 'Approuvé'     },
  { key: 'planning',      label: 'Planification' },
  { key: 'graphic_design',label: 'Design'       },
  { key: 'development',   label: 'Développement' },
  { key: 'code_review',   label: 'Revue'        },
  { key: 'done',          label: 'Terminé'      },
]

const STATUS_CONFIG: Record<WorkflowStatus, { label: string; badge: string; icon: typeof ShieldCheck }> = {
  draft:     { label: 'Brouillon',  badge: 'badge-gray',   icon: FileEdit    },
  validated: { label: 'Validé',     badge: 'badge-green',  icon: ShieldCheck },
  locked:    { label: 'Verrouillé', badge: 'badge-orange', icon: Lock        },
}

// ─── Small helpers ─────────────────────────────────────────────────────────────

/**
 * Icon reflecting the execution status of a workflow step.
 */
function StepStatusIcon({ status }: { status: WorkflowStep['status'] }) {
  const map: Record<WorkflowStep['status'], ReactNode> = {
    pending: <Clock      className="w-4 h-4" style={{ color: 'var(--muted)' }} />,
    running: <Play       className="w-4 h-4 text-blue-500" />,
    done:    <CheckCircle className="w-4 h-4 text-green-500" />,
    error:   <AlertCircle className="w-4 h-4 text-red-500" />,
    skipped: <SkipForward className="w-4 h-4" style={{ color: 'var(--muted)' }} />,
  }
  return map[status] ?? null
}

// ─── Visual lifecycle pipeline (U10) ─────────────────────────────────────────

/**
 * Full story lifecycle pipeline.
 * Stages that match a workflow step's storyStatusTrigger are highlighted as
 * "agent steps" and display the role and skill configured for that step.
 */
function LifecyclePipeline({ steps }: { steps: WorkflowStep[] }) {
  const stepByTrigger = new Map<string, WorkflowStep>()
  for (const s of steps) {
    if (s.storyStatusTrigger) stepByTrigger.set(s.storyStatusTrigger, s)
  }

  return (
    <div className="overflow-x-auto pb-2">
      <div className="flex items-start gap-0 min-w-max">
        {LIFECYCLE_STAGES.map((stage, i) => {
          const agentStep = stepByTrigger.get(stage.key)
          const isAgent   = !!agentStep
          const isLast    = i === LIFECYCLE_STAGES.length - 1

          return (
            <div key={stage.key} className="flex items-start">
              {/* Stage node */}
              <div className="flex flex-col items-center gap-1.5 w-28">
                {/* Circle */}
                <div
                  className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 border-2 transition-colors"
                  style={{
                    background:  isAgent ? 'var(--brand)'     : 'var(--surface2)',
                    borderColor: isAgent ? 'var(--brand)'     : 'var(--border)',
                    color:       isAgent ? 'var(--brand-text)': 'var(--muted)',
                  }}
                >
                  {isAgent ? <User className="w-4 h-4" /> : <span className="text-xs font-bold">{i + 1}</span>}
                </div>

                {/* Stage label */}
                <span
                  className="text-xs font-medium text-center leading-tight"
                  style={{ color: isAgent ? 'var(--brand)' : 'var(--muted)' }}
                >
                  {stage.label}
                </span>

                {/* Agent step details */}
                {agentStep && (
                  <div className="flex flex-col items-center gap-1 text-center">
                    {agentStep.roleSlug && (
                      <span className="flex items-center gap-0.5 text-xs" style={{ color: 'var(--text)' }}>
                        <User className="w-3 h-3 flex-shrink-0" style={{ color: 'var(--muted)' }} />
                        {agentStep.roleSlug}
                      </span>
                    )}
                    {agentStep.skillSlug && (
                      <span className="flex items-center gap-0.5 text-xs" style={{ color: 'var(--muted)' }}>
                        <BookOpen className="w-3 h-3 flex-shrink-0" />
                        {agentStep.skillSlug}
                      </span>
                    )}
                    {agentStep.condition && (
                      <span className="flex items-center gap-0.5 text-xs mt-0.5" style={{ color: 'var(--muted)' }}>
                        <AlertTriangle className="w-3 h-3 flex-shrink-0 text-yellow-500" />
                        Conditionnelle
                      </span>
                    )}
                  </div>
                )}
              </div>

              {/* Connector arrow */}
              {!isLast && (
                <div className="flex items-center mt-4 flex-shrink-0">
                  <div className="w-4 h-0.5" style={{ background: 'var(--border)' }} />
                  <ChevronRight className="w-3 h-3 -ml-1 flex-shrink-0" style={{ color: 'var(--border)' }} />
                </div>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

// ─── Workflow Form ─────────────────────────────────────────────────────────────

/**
 * Create / edit form for a Workflow.
 * Rendered inside a Modal for create and edit actions.
 */
function WorkflowForm({
  initial,
  onSubmit,
  loading,
  onCancel,
}: {
  initial?: Partial<WorkflowPayload>
  onSubmit: (d: WorkflowPayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const [name,        setName]       = useState(initial?.name        ?? '')
  const [description, setDescription]= useState(initial?.description ?? '')
  const [trigger,     setTrigger]    = useState<WorkflowPayload['trigger']>(initial?.trigger ?? 'manual')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ name, description: description || undefined, trigger }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Nom *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder="Workflow de revue de code" />
      </div>
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Description</label>
        <textarea className="input resize-none" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Déclencheur</label>
        <select className="input" value={trigger} onChange={(e) => setTrigger(e.target.value as WorkflowPayload['trigger'])}>
          <option value="manual">Manuel</option>
          <option value="vcs_event">Événement VCS (push, PR…)</option>
          <option value="scheduled">Planifié</option>
        </select>
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Annuler</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? 'Enregistrement…' : 'Enregistrer'}</button>
      </div>
    </form>
  )
}

// ─── Workflows List ────────────────────────────────────────────────────────────

/** Grid of workflow cards with CRUD actions. */
function WorkflowsList() {
  const qc = useQueryClient()
  const [createOpen,   setCreateOpen]   = useState(false)
  const [editTarget,   setEditTarget]   = useState<Workflow | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<Workflow | null>(null)

  const { data: workflows, isLoading, error, refetch } = useQuery({
    queryKey: ['workflows'],
    queryFn: workflowsApi.list,
  })

  const createMutation = useMutation({
    mutationFn: workflowsApi.create,
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['workflows'] }); setCreateOpen(false) },
  })
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: WorkflowPayload }) => workflowsApi.update(id, data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['workflows'] }); setEditTarget(null) },
  })
  const deleteMutation = useMutation({
    mutationFn: (id: string) => workflowsApi.delete(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['workflows'] }); setDeleteTarget(null) },
  })

  if (isLoading) return <PageSpinner />
  if (error)     return <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />

  return (
    <>
      <PageHeader
        title="Workflows"
        description="Définissez des séquences multi-étapes exécutées par vos équipes d'agents."
        action={
          <button className="btn-primary" onClick={() => setCreateOpen(true)}>
            <Plus className="w-4 h-4" /> Nouveau workflow
          </button>
        }
      />

      {workflows?.length === 0 ? (
        <EmptyState
          icon={GitBranch}
          title="Aucun workflow"
          description="Créez un workflow pour orchestrer vos agents étape par étape."
          action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> Nouveau workflow</button>}
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {workflows?.map((wf) => {
            const sc = STATUS_CONFIG[wf.status] ?? STATUS_CONFIG.draft
            const StatusIcon = sc.icon
            return (
              <div key={wf.id} className="card p-5 flex flex-col gap-3 hover:shadow-md transition-shadow">
                <div className="flex items-start justify-between gap-2">
                  <Link
                    to={`/workflows/${wf.id}`}
                    className="font-semibold hover:underline"
                    style={{ color: 'var(--text)' }}
                  >
                    {wf.name}
                  </Link>
                  <div className="flex gap-1 flex-shrink-0">
                    {wf.isEditable && (
                      <button onClick={() => setEditTarget(wf)} className="p-1.5 transition-colors" style={{ color: 'var(--muted)' }} title="Modifier">
                        <Pencil className="w-4 h-4" />
                      </button>
                    )}
                    <button onClick={() => setDeleteTarget(wf)} className="p-1.5 transition-colors hover:text-red-500" style={{ color: 'var(--muted)' }} title="Supprimer">
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                </div>

                {wf.description && <p className="text-sm line-clamp-2" style={{ color: 'var(--muted)' }}>{wf.description}</p>}

                <div className="flex items-center gap-2 flex-wrap">
                  <span className={TRIGGER_COLORS[wf.trigger]}>{TRIGGER_LABELS[wf.trigger]}</span>
                  {wf.team && <span className="badge-gray">{wf.team.name}</span>}
                  <span className={`${sc.badge} flex items-center gap-1`}>
                    <StatusIcon className="w-3 h-3" />
                    {sc.label}
                  </span>
                </div>

                <div className="mt-auto flex items-center justify-between text-xs" style={{ color: 'var(--muted)' }}>
                  <span>
                    {typeof wf.steps === 'number'
                      ? `${wf.steps} étape${wf.steps !== 1 ? 's' : ''}`
                      : `${wf.steps.length} étape${wf.steps.length !== 1 ? 's' : ''}`}
                  </span>
                  <div className="flex items-center gap-1">
                    {wf.isUsable
                      ? <><CheckCircle className="w-3.5 h-3.5 text-green-500" /> Actif</>
                      : <><XCircle    className="w-3.5 h-3.5" style={{ color: 'var(--muted)' }} /> Inactif</>}
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Nouveau workflow">
        <WorkflowForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} />
      </Modal>

      <Modal open={!!editTarget} onClose={() => setEditTarget(null)} title="Modifier le workflow">
        {editTarget && (
          <WorkflowForm
            initial={{ name: editTarget.name, description: editTarget.description ?? '', trigger: editTarget.trigger }}
            onSubmit={(d) => updateMutation.mutate({ id: editTarget.id, data: d })}
            loading={updateMutation.isPending}
            onCancel={() => setEditTarget(null)}
          />
        )}
      </Modal>

      <ConfirmDialog
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
        message={`Supprimer le workflow "${deleteTarget?.name}" ? Cette action est irréversible.`}
        loading={deleteMutation.isPending}
      />
    </>
  )
}

// ─── Workflow Detail ───────────────────────────────────────────────────────────

/**
 * Full detail view for a workflow:
 * - Metadata header with status badge and Validate button (U11)
 * - Visual lifecycle pipeline showing which stages have configured agent steps (U10)
 * - Ordered step list with role, skill, condition, execution output
 */
function WorkflowDetail() {
  const { id }   = useParams<{ id: string }>()
  const qc       = useQueryClient()
  const [validateError, setValidateError] = useState<string | null>(null)

  const { data: workflow, isLoading, error, refetch } = useQuery({
    queryKey: ['workflows', id],
    queryFn:  () => workflowsApi.get(id!),
    enabled:  !!id,
  })

  const validateMutation = useMutation({
    mutationFn: () => workflowsApi.validate(id!),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['workflows', id] }); qc.invalidateQueries({ queryKey: ['workflows'] }) },
    onError:   (e: Error) => setValidateError(e.message),
  })

  if (isLoading) return <PageSpinner />
  if (error || !workflow) return <ErrorMessage message={(error as Error)?.message ?? 'Workflow introuvable'} onRetry={() => refetch()} />

  const steps   = Array.isArray(workflow.steps) ? [...workflow.steps].sort((a, b) => a.stepOrder - b.stepOrder) : []
  const sc      = STATUS_CONFIG[workflow.status] ?? STATUS_CONFIG.draft
  const StatusIcon = sc.icon

  return (
    <>
      <Link to="/workflows" className="inline-flex items-center gap-1 text-sm mb-4" style={{ color: 'var(--muted)' }}>
        <ArrowLeft className="w-4 h-4" /> Workflows
      </Link>

      {/* Header */}
      <div className="flex items-start justify-between gap-4 mb-4 flex-wrap">
        <div>
          <h1 className="text-xl font-bold" style={{ color: 'var(--text)' }}>{workflow.name}</h1>
          {workflow.description && <p className="text-sm mt-1" style={{ color: 'var(--muted)' }}>{workflow.description}</p>}
        </div>

        {/* Validate button — only shown for draft workflows */}
        {workflow.status === 'draft' && (
          <button
            className="btn-primary flex items-center gap-1.5 flex-shrink-0"
            onClick={() => { setValidateError(null); validateMutation.mutate() }}
            disabled={validateMutation.isPending}
          >
            <ShieldCheck className="w-4 h-4" />
            {validateMutation.isPending ? 'Validation…' : 'Valider le workflow'}
          </button>
        )}
      </div>

      {/* Metadata badges */}
      <div className="flex items-center gap-2 mb-6 flex-wrap">
        <span className={TRIGGER_COLORS[workflow.trigger]}>{TRIGGER_LABELS[workflow.trigger]}</span>
        {workflow.team && <span className="badge-gray">Équipe : {workflow.team.name}</span>}
        <span className={`${sc.badge} flex items-center gap-1`}>
          <StatusIcon className="w-3 h-3" />
          {sc.label}
        </span>
        {workflow.isUsable
          ? <span className="badge-green flex items-center gap-1"><CheckCircle className="w-3 h-3" /> Actif</span>
          : <span className="badge-gray flex items-center gap-1"><XCircle className="w-3 h-3" /> Inactif</span>}
      </div>

      {validateError && (
        <div className="mb-4 p-3 rounded text-sm text-red-700 bg-red-50 border border-red-200">
          {validateError}
        </div>
      )}

      {/* ── Lifecycle pipeline (U10) ── */}
      <section className="card p-5 mb-6">
        <h2 className="text-sm font-semibold mb-4" style={{ color: 'var(--muted)' }}>
          Cycle de vie — étapes agents configurées
        </h2>
        {steps.length === 0 ? (
          <p className="text-sm" style={{ color: 'var(--muted)' }}>
            Aucune étape configurée. Les étapes avec un déclencheur de cycle de vie apparaîtront ici.
          </p>
        ) : (
          <LifecyclePipeline steps={steps} />
        )}
      </section>

      {/* ── Steps list ── */}
      <h2 className="text-base font-semibold mb-3" style={{ color: 'var(--text)' }}>
        Étapes ({steps.length})
      </h2>

      {steps.length === 0 ? (
        <EmptyState icon={GitBranch} title="Aucune étape" description="Les étapes sont définies lors de l'exécution du workflow." />
      ) : (
        <div className="space-y-3">
          {steps.map((step) => (
            <div key={step.id} className="card p-4 flex gap-4 items-start">
              {/* Step number / status */}
              <div
                className="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold"
                style={{ background: 'var(--surface2)', color: 'var(--muted)' }}
              >
                {step.stepOrder}
              </div>

              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1.5 flex-wrap">
                  <p className="font-medium" style={{ color: 'var(--text)' }}>{step.name || step.outputKey}</p>
                  <StepStatusIcon status={step.status} />
                  {step.storyStatusTrigger && (
                    <span className="badge-blue text-xs">↳ {step.storyStatusTrigger}</span>
                  )}
                </div>

                <div className="flex flex-wrap gap-2 text-xs">
                  {step.roleSlug  && <span className="badge-blue">{step.roleSlug}</span>}
                  {step.skillSlug && <span className="badge-orange">{step.skillSlug}</span>}
                  <span className="badge-gray">sortie : {step.outputKey}</span>
                </div>

                {step.condition && (
                  <div className="mt-2 flex items-start gap-1.5 text-xs" style={{ color: 'var(--muted)' }}>
                    <AlertTriangle className="w-3.5 h-3.5 text-yellow-500 flex-shrink-0 mt-0.5" />
                    <span>Condition : {step.condition}</span>
                  </div>
                )}

                {step.lastOutput && (
                  <pre className="mt-2 text-xs rounded p-2 overflow-x-auto max-h-24 font-mono" style={{ background: 'var(--surface2)', color: 'var(--muted)' }}>
                    {step.lastOutput}
                  </pre>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </>
  )
}

// ─── Page router ───────────────────────────────────────────────────────────────

export default function WorkflowsPage() {
  return (
    <Routes>
      <Route index element={<WorkflowsList />} />
      <Route path=":id" element={<WorkflowDetail />} />
    </Routes>
  )
}
