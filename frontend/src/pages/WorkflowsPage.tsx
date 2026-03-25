import { useState } from 'react'
import { Routes, Route, useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, GitBranch, ArrowLeft, Pencil, Trash2, CheckCircle, XCircle, Clock, Play, SkipForward, AlertCircle } from 'lucide-react'
import { workflowsApi } from '@/api/workflows'
import type { WorkflowPayload } from '@/api/workflows'
import type { Workflow, WorkflowStep } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'

function fmt(date: string) {
  return new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

const triggerLabels: Record<string, string> = {
  manual: 'Manual',
  vcs_event: 'VCS event',
  scheduled: 'Scheduled',
}

const triggerColors: Record<string, string> = {
  manual: 'badge-blue',
  vcs_event: 'badge-orange',
  scheduled: 'badge-gray',
}

function StepStatusIcon({ status }: { status: WorkflowStep['status'] }) {
  const map = {
    pending: <Clock className="w-4 h-4 text-gray-400" />,
    running: <Play className="w-4 h-4 text-blue-500" />,
    done: <CheckCircle className="w-4 h-4 text-green-500" />,
    error: <AlertCircle className="w-4 h-4 text-red-500" />,
    skipped: <SkipForward className="w-4 h-4 text-gray-300" />,
  }
  return map[status] ?? null
}

// ─── Workflow Form ────────────────────────────────────────────────────────────

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
  const [name, setName] = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [trigger, setTrigger] = useState<WorkflowPayload['trigger']>(initial?.trigger ?? 'manual')
  const [isActive, setIsActive] = useState(initial?.isActive ?? true)

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ name, description: description || undefined, trigger, isActive }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Name *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder="Code review workflow" />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea className="input resize-none" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Trigger</label>
        <select className="input" value={trigger} onChange={(e) => setTrigger(e.target.value as WorkflowPayload['trigger'])}>
          <option value="manual">Manual</option>
          <option value="vcs_event">VCS event (push, PR…)</option>
          <option value="scheduled">Scheduled</option>
        </select>
      </div>
      <label className="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} className="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
        <span className="text-sm text-gray-700">Workflow active</span>
      </label>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Cancel</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? 'Saving…' : 'Save'}</button>
      </div>
    </form>
  )
}

// ─── Workflows List ───────────────────────────────────────────────────────────

function WorkflowsList() {
  const qc = useQueryClient()
  const [createOpen, setCreateOpen] = useState(false)
  const [editTarget, setEditTarget] = useState<Workflow | null>(null)
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
  if (error) return <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />

  return (
    <>
      <PageHeader
        title="Workflows"
        description="Define multi-step sequences executed by your agent teams."
        action={
          <button className="btn-primary" onClick={() => setCreateOpen(true)}>
            <Plus className="w-4 h-4" /> New workflow
          </button>
        }
      />

      {workflows?.length === 0 ? (
        <EmptyState
          icon={GitBranch}
          title="No workflows yet"
          description="Create a workflow to orchestrate your agents step by step."
          action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> New workflow</button>}
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {workflows?.map((wf) => (
            <div key={wf.id} className="card p-5 flex flex-col gap-3 hover:shadow-md transition-shadow">
              <div className="flex items-start justify-between gap-2">
                <Link
                  to={`/workflows/${wf.id}`}
                  className="font-semibold text-gray-900 hover:text-brand-600 transition-colors"
                >
                  {wf.name}
                </Link>
                <div className="flex gap-1 flex-shrink-0">
                  <button onClick={() => setEditTarget(wf)} className="p-1.5 text-gray-400 hover:text-gray-600" title="Edit">
                    <Pencil className="w-4 h-4" />
                  </button>
                  <button onClick={() => setDeleteTarget(wf)} className="p-1.5 text-gray-400 hover:text-red-500" title="Delete">
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              </div>

              {wf.description && <p className="text-sm text-gray-500 line-clamp-2">{wf.description}</p>}

              <div className="flex items-center gap-2 flex-wrap">
                <span className={triggerColors[wf.trigger]}>{triggerLabels[wf.trigger]}</span>
                {wf.team && <span className="badge-gray">{wf.team.name}</span>}
              </div>

              <div className="mt-auto flex items-center justify-between text-xs text-gray-400">
                <span>
                  {typeof wf.steps === 'number'
                    ? `${wf.steps} step${wf.steps !== 1 ? 's' : ''}`
                    : `${wf.steps.length} step${wf.steps.length !== 1 ? 's' : ''}`}
                </span>
                <div className="flex items-center gap-1">
                  {wf.isActive
                    ? <><CheckCircle className="w-3.5 h-3.5 text-green-500" /> Active</>
                    : <><XCircle className="w-3.5 h-3.5 text-gray-300" /> Inactive</>}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="New workflow">
        <WorkflowForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} />
      </Modal>

      <Modal open={!!editTarget} onClose={() => setEditTarget(null)} title="Edit workflow">
        {editTarget && (
          <WorkflowForm
            initial={{ name: editTarget.name, description: editTarget.description ?? '', trigger: editTarget.trigger, isActive: editTarget.isActive }}
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
        message={`Delete workflow "${deleteTarget?.name}"? This action cannot be undone.`}
        loading={deleteMutation.isPending}
      />
    </>
  )
}

// ─── Workflow Detail ──────────────────────────────────────────────────────────

function WorkflowDetail() {
  const { id } = useParams<{ id: string }>()

  const { data: workflow, isLoading, error, refetch } = useQuery({
    queryKey: ['workflows', id],
    queryFn: () => workflowsApi.get(id!),
    enabled: !!id,
  })

  if (isLoading) return <PageSpinner />
  if (error || !workflow) return <ErrorMessage message={(error as Error)?.message ?? 'Workflow not found'} onRetry={() => refetch()} />

  return (
    <>
      <Link to="/workflows" className="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 mb-4">
        <ArrowLeft className="w-4 h-4" /> Workflows
      </Link>

      <PageHeader title={workflow.name} description={workflow.description ?? undefined} />

      {/* Meta */}
      <div className="flex items-center gap-3 mb-6 flex-wrap">
        <span className={triggerColors[workflow.trigger]}>{triggerLabels[workflow.trigger]}</span>
        {workflow.team && <span className="badge-gray">Team: {workflow.team.name}</span>}
        {workflow.isActive
          ? <span className="badge-green">Active</span>
          : <span className="badge-gray">Inactive</span>}
      </div>

      {/* Steps */}
      <h2 className="text-base font-semibold text-gray-900 mb-3">Steps ({workflow.steps.length})</h2>

      {workflow.steps.length === 0 ? (
        <EmptyState icon={GitBranch} title="No steps" description="Steps are defined when running the workflow." />
      ) : (
        <div className="space-y-3">
          {workflow.steps
            .sort((a, b) => a.stepOrder - b.stepOrder)
            .map((step) => (
              <div key={step.id} className="card p-4 flex gap-4 items-start">
                <div className="flex-shrink-0 w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center text-sm font-bold text-gray-500">
                  {step.stepOrder}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 mb-1">
                    <p className="font-medium text-gray-900">{step.name || step.outputKey}</p>
                    <StepStatusIcon status={step.status} />
                  </div>
                  <div className="flex flex-wrap gap-2 text-xs">
                    {step.roleSlug && <span className="badge-blue">{step.roleSlug}</span>}
                    {step.skillSlug && <span className="badge-orange">{step.skillSlug}</span>}
                    <span className="badge-gray">out: {step.outputKey}</span>
                  </div>
                  {step.lastOutput && (
                    <pre className="mt-2 text-xs text-gray-500 bg-gray-50 rounded p-2 overflow-x-auto max-h-24 font-mono">
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

// ─── Page router ──────────────────────────────────────────────────────────────

export default function WorkflowsPage() {
  return (
    <Routes>
      <Route index element={<WorkflowsList />} />
      <Route path=":id" element={<WorkflowDetail />} />
    </Routes>
  )
}
