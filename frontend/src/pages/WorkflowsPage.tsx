/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState, type ReactNode } from 'react'
import { Routes, Route, useParams, Link, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Plus, GitBranch, ArrowLeft, CheckCircle, XCircle,
  Clock, Play, SkipForward, AlertCircle, Lock,
  ChevronRight, User, AlertTriangle, Copy, Power, Pencil,
} from 'lucide-react'
import { workflowsApi } from '@/api/workflows'
import { useTranslation } from '@/hooks/useTranslation'
import type { WorkflowPayload } from '@/api/workflows'
import type { WorkflowStep } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'

// ─── Constants ────────────────────────────────────────────────────────────────

const WORKFLOWS_PAGE_TRANSLATION_KEYS = [
  'common.action.refresh',
  'common.action.cancel',
  'workflows.ui.form.name_label',
  'workflows.ui.form.name_placeholder',
  'workflows.ui.form.description_label',
  'workflows.ui.form.trigger_label',
  'workflows.ui.form.immutable_hint',
  'workflows.ui.form.submit_loading',
  'workflows.ui.form.submit_create',
  'workflows.ui.form.submit_save',
  'workflows.ui.form.modal_create_title',
  'workflows.ui.form.modal_edit_title',
  'workflows.ui.trigger.manual',
  'workflows.ui.trigger.vcs_event',
  'workflows.ui.trigger.scheduled',
  'workflow.list.loading',
  'workflow.item.loading',
  'workflows.ui.status.immutable_badge',
  'workflows.ui.status.immutable_notice',
  'workflows.ui.status.active',
  'workflows.ui.status.inactive',
  'workflows.ui.status.activate',
  'workflows.ui.status.deactivate',
  'workflows.ui.status.duplicate',
  'workflows.ui.status.edit',
  'workflows.ui.status.action_loading',
  'workflows.ui.lifecycle.new',
  'workflows.ui.lifecycle.ready',
  'workflows.ui.lifecycle.planning',
  'workflows.ui.lifecycle.graphic_design',
  'workflows.ui.lifecycle.development',
  'workflows.ui.lifecycle.code_review',
  'workflows.ui.lifecycle.done',
  'workflows.ui.lifecycle.conditional',
  'workflows.ui.lifecycle.configured_title',
  'workflows.ui.lifecycle.configured_empty',
  'workflows.ui.lifecycle.transition.manual',
  'workflows.ui.lifecycle.transition.automatic',
  'workflows.ui.page.title',
  'workflows.ui.page.description',
  'workflows.ui.page.empty_title',
  'workflows.ui.page.create_button',
  'workflows.ui.page.empty_description',
  'workflows.ui.detail.not_found',
  'workflows.ui.detail.back_link',
  'workflows.ui.detail.output_label',
  'workflows.ui.detail.condition_label',
  'workflows.ui.detail.actions_label',
  'workflows.ui.detail.create_with_ticket',
  'workflows.ui.detail.steps_title',
  'workflows.ui.detail.empty_steps_title',
  'workflows.ui.detail.empty_steps_description',
  'workflows.ui.count.step_one',
  'workflows.ui.count.step_other',
] as const

const TRIGGER_LABEL_KEYS: Record<string, string> = {
  manual: 'workflows.ui.trigger.manual',
  vcs_event: 'workflows.ui.trigger.vcs_event',
  scheduled: 'workflows.ui.trigger.scheduled',
}

const TRIGGER_COLORS: Record<string, string> = {
  manual: 'badge-blue',
  vcs_event: 'badge-orange',
  scheduled: 'badge-gray',
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
 * Ordered workflow pipeline based on the actual configured steps.
 */
function LifecyclePipeline({ steps, t }: { steps: WorkflowStep[]; t: (key: string) => string }) {
  const orderedSteps = [...steps].sort((left, right) => left.stepOrder - right.stepOrder)

  return (
    <div className="overflow-x-auto pb-2">
      <div className="flex items-start gap-0 min-w-max">
        {orderedSteps.map((step, index) => {
          const isLast = index === orderedSteps.length - 1

          return (
            <div key={step.id} className="flex items-start">
              <div className="flex flex-col items-center gap-1.5 w-28">
                <div
                  className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 border-2 transition-colors"
                  style={{
                    background: 'var(--brand)',
                    borderColor: 'var(--brand)',
                    color: 'var(--brand-text)',
                  }}
                >
                  <span className="text-xs font-bold">{step.stepOrder}</span>
                </div>

                <span
                  className="text-xs font-medium text-center leading-tight"
                  style={{ color: 'var(--brand)' }}
                >
                  {step.name}
                </span>

                <div className="flex flex-col items-center gap-1 text-center">
                  {step.actions.slice(0, 2).map(({ id, agentAction }) => (
                    <span key={id} className="flex items-center gap-0.5 text-xs" style={{ color: 'var(--text)' }}>
                      <User className="w-3 h-3 flex-shrink-0" style={{ color: 'var(--muted)' }} />
                      {agentAction.label}
                    </span>
                  ))}
                  <span className="text-[11px]" style={{ color: 'var(--muted)' }}>
                    {step.outputKey}
                  </span>
                  {step.condition && (
                    <span className="flex items-center gap-0.5 text-xs mt-0.5" style={{ color: 'var(--muted)' }}>
                      <AlertTriangle className="w-3 h-3 flex-shrink-0 text-yellow-500" />
                      {t('workflows.ui.lifecycle.conditional')}
                    </span>
                  )}
                </div>
                {step.actions.length === 0 && (
                  <div className="flex flex-col items-center gap-1 text-center">
                    <span className="text-xs" style={{ color: 'var(--muted)' }}>
                      {t('workflows.ui.detail.empty_steps_description')}
                    </span>
                  </div>
                )}
              </div>

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
 * Create form for a Workflow.
 * Rendered inside a Modal and persisted as an immutable definition.
 */
function WorkflowForm({
  initial,
  onSubmit,
  loading,
  onCancel,
  t,
}: {
  initial?: Partial<WorkflowPayload>
  onSubmit: (d: WorkflowPayload) => void
  loading: boolean
  onCancel: () => void
  t: (key: string) => string
}) {
  const [name,        setName]       = useState(initial?.name ?? '')
  const [description, setDescription]= useState(initial?.description ?? '')
  const [trigger,     setTrigger]    = useState<WorkflowPayload['trigger']>(initial?.trigger ?? 'manual')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ name, description: description || undefined, trigger }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{t('workflows.ui.form.name_label')} *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder={t('workflows.ui.form.name_placeholder')} />
      </div>
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{t('workflows.ui.form.description_label')}</label>
        <textarea className="input resize-none" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{t('workflows.ui.form.trigger_label')}</label>
        <select className="input" value={trigger} onChange={(e) => setTrigger(e.target.value as WorkflowPayload['trigger'])}>
          <option value="manual">{t('workflows.ui.trigger.manual')}</option>
          <option value="vcs_event">{t('workflows.ui.trigger.vcs_event')}</option>
          <option value="scheduled">{t('workflows.ui.trigger.scheduled')}</option>
        </select>
      </div>
      <p className="text-xs" style={{ color: 'var(--muted)' }}>
        {t('workflows.ui.form.immutable_hint')}
      </p>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">{t('common.action.cancel')}</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? t('workflows.ui.form.submit_loading') : (initial ? t('workflows.ui.form.submit_save') : t('workflows.ui.form.submit_create'))}</button>
      </div>
    </form>
  )
}

// ─── Workflows List ────────────────────────────────────────────────────────────

/** Grid of workflow cards with CRUD actions. */
function WorkflowsList() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [createOpen,   setCreateOpen]   = useState(false)
  const { t } = useTranslation(WORKFLOWS_PAGE_TRANSLATION_KEYS)

  const { data: workflows, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['workflows'],
    queryFn: workflowsApi.list,
  })

  const createMutation = useMutation({
    mutationFn: workflowsApi.create,
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['workflows'] }); setCreateOpen(false) },
  })
  const duplicateMutation = useMutation({
    mutationFn: (id: string) => workflowsApi.duplicate(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['workflows'] }) },
  })
  const activateMutation = useMutation({
    mutationFn: (id: string) => workflowsApi.activate(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['workflows'] }) },
  })
  const deactivateMutation = useMutation({
    mutationFn: (id: string) => workflowsApi.deactivate(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['workflows'] }) },
  })

  if (isLoading) return <PageSpinner />
  if (error)     return <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />

  return (
    <>
      <PageHeader
        title={t('workflows.ui.page.title')}
        description={t('workflows.ui.page.description')}
        onRefresh={() => qc.invalidateQueries({ queryKey: ['workflows'] })}
        refreshTitle={t('common.action.refresh')}
        action={
          <button className="btn-primary" onClick={() => setCreateOpen(true)}>
            <Plus className="w-4 h-4" /> {t('workflows.ui.page.create_button')}
          </button>
        }
      />

      {workflows?.length === 0 ? (
        <EmptyState
          icon={GitBranch}
          title={t('workflows.ui.page.empty_title')}
          description={t('workflows.ui.page.empty_description')}
          action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> {t('workflows.ui.page.create_button')}</button>}
        />
      ) : (
        <div className="relative">
          <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={t('workflow.list.loading')} />
          <div className="list-workflow grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {workflows?.map((wf) => {
            return (
              <div
                key={wf.id}
                role="button"
                tabIndex={0}
                onClick={() => navigate(`/workflows/${wf.id}`)}
                onKeyDown={(e) => e.key === 'Enter' && navigate(`/workflows/${wf.id}`)}
                className="item-workflow card p-5 flex flex-col gap-3 hover:shadow-md transition-shadow text-left cursor-pointer"
              >
                <div className="flex items-start justify-between gap-2">
                  <span className="font-semibold hover:underline" style={{ color: 'var(--text)' }}>
                    {wf.name}
                  </span>
                  {!wf.isEditable && (
                    <span className="badge-gray flex items-center gap-1 flex-shrink-0">
                      <Lock className="w-3 h-3" />
                      {t('workflows.ui.status.immutable_badge')}
                    </span>
                  )}
                </div>

                {wf.description && <p className="text-sm line-clamp-2" style={{ color: 'var(--muted)' }}>{wf.description}</p>}

                <div className="flex items-center gap-2 flex-wrap">
                  <span className={TRIGGER_COLORS[wf.trigger]}>{t(TRIGGER_LABEL_KEYS[wf.trigger] ?? wf.trigger)}</span>
                </div>

                <div className="mt-auto flex items-center justify-between text-xs" style={{ color: 'var(--muted)' }}>
                  <span>
                    {typeof wf.steps === 'number'
                      ? `${wf.steps} ${t(wf.steps !== 1 ? 'workflows.ui.count.step_other' : 'workflows.ui.count.step_one')}`
                      : `${wf.steps.length} ${t(wf.steps.length !== 1 ? 'workflows.ui.count.step_other' : 'workflows.ui.count.step_one')}`}
                  </span>
                  <div className="flex items-center gap-1">
                    {wf.isActive
                      ? <><CheckCircle className="w-3.5 h-3.5 text-green-500" /> {t('workflows.ui.status.active')}</>
                      : <><XCircle    className="w-3.5 h-3.5" style={{ color: 'var(--muted)' }} /> {t('workflows.ui.status.inactive')}</>}
                  </div>
                </div>

                <div className="flex items-center gap-2 pt-2">
                  <button
                    type="button"
                    className="btn-secondary inline-flex items-center gap-2"
                    onClick={(e) => { e.stopPropagation(); duplicateMutation.mutate(wf.id) }}
                    disabled={duplicateMutation.isPending}
                    title={t('workflows.ui.status.duplicate')}
                  >
                    <Copy className="w-4 h-4" />
                    {duplicateMutation.isPending ? t('workflows.ui.status.action_loading') : t('workflows.ui.status.duplicate')}
                  </button>

                  {wf.isActive ? (
                    <button
                      type="button"
                      className="btn-secondary inline-flex items-center gap-2"
                      onClick={(e) => { e.stopPropagation(); deactivateMutation.mutate(wf.id) }}
                      disabled={deactivateMutation.isPending}
                      title={t('workflows.ui.status.deactivate')}
                    >
                      <Power className="w-4 h-4" />
                      {deactivateMutation.isPending ? t('workflows.ui.status.action_loading') : t('workflows.ui.status.deactivate')}
                    </button>
                  ) : (
                    <button
                      type="button"
                      className="btn-primary inline-flex items-center gap-2"
                      onClick={(e) => { e.stopPropagation(); activateMutation.mutate(wf.id) }}
                      disabled={activateMutation.isPending}
                      title={t('workflows.ui.status.activate')}
                    >
                      <Power className="w-4 h-4" />
                      {activateMutation.isPending ? t('workflows.ui.status.action_loading') : t('workflows.ui.status.activate')}
                    </button>
                  )}
                </div>
              </div>
            )
          })}
        </div>
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title={t('workflows.ui.form.modal_create_title')}>
        <WorkflowForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} t={t} />
      </Modal>
    </>
  )
}

// ─── Workflow Detail ───────────────────────────────────────────────────────────

/**
 * Full detail view for a workflow:
 * - Metadata header with activation and edit actions
 * - Visual lifecycle pipeline showing which stages have configured workflow actions (U10)
 * - Ordered step list with actions, transition mode, condition, execution output
 */
function WorkflowDetail() {
  const { id }   = useParams<{ id: string }>()
  const qc = useQueryClient()
  const [editOpen, setEditOpen] = useState(false)
  const { t } = useTranslation(WORKFLOWS_PAGE_TRANSLATION_KEYS)

  const { data: workflow, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['workflows', id],
    queryFn:  () => workflowsApi.get(id!),
    enabled:  !!id,
  })

  const duplicateMutation = useMutation({
    mutationFn: () => workflowsApi.duplicate(id!),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['workflows'] }); qc.invalidateQueries({ queryKey: ['workflows', id] }) },
  })
  const updateMutation = useMutation({
    mutationFn: (data: WorkflowPayload) => workflowsApi.update(id!, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['workflows'] })
      qc.invalidateQueries({ queryKey: ['workflows', id] })
      setEditOpen(false)
    },
  })
  const activateMutation = useMutation({
    mutationFn: () => workflowsApi.activate(id!),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['workflows'] }); qc.invalidateQueries({ queryKey: ['workflows', id] }) },
  })
  const deactivateMutation = useMutation({
    mutationFn: () => workflowsApi.deactivate(id!),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['workflows'] }); qc.invalidateQueries({ queryKey: ['workflows', id] }) },
  })

  if (isLoading) return <PageSpinner />
  if (error || !workflow) return <ErrorMessage message={(error as Error)?.message ?? t('workflows.ui.detail.not_found')} onRetry={() => refetch()} />

  const steps   = Array.isArray(workflow.steps) ? [...workflow.steps].sort((a, b) => a.stepOrder - b.stepOrder) : []
  return (
    <>
      <Link to="/workflows" className="inline-flex items-center gap-1 text-sm mb-4" style={{ color: 'var(--muted)' }}>
        <ArrowLeft className="w-4 h-4" /> {t('workflows.ui.detail.back_link')}
      </Link>

      <PageHeader
        title={workflow.name}
        description={workflow.description ?? undefined}
        onRefresh={() => qc.invalidateQueries({ queryKey: ['workflows', id] })}
        refreshTitle={t('common.action.refresh')}
        action={
          <div className="flex items-center gap-2 flex-wrap">
            {workflow.isEditable ? (
              <button
                type="button"
                className="btn-secondary inline-flex items-center gap-2"
                onClick={() => setEditOpen(true)}
              >
                <Pencil className="w-4 h-4" />
                {t('workflows.ui.status.edit')}
              </button>
            ) : (
              <span className="badge-gray flex items-center gap-1.5 flex-shrink-0">
                <Lock className="w-3.5 h-3.5" />
                {t('workflows.ui.status.immutable_notice')}
              </span>
            )}
            <button
              type="button"
              className="btn-secondary inline-flex items-center gap-2"
              onClick={() => duplicateMutation.mutate()}
              disabled={duplicateMutation.isPending}
            >
              <Copy className="w-4 h-4" />
              {duplicateMutation.isPending ? t('workflows.ui.status.action_loading') : t('workflows.ui.status.duplicate')}
            </button>
            {workflow.isActive ? (
              <button
                type="button"
                className="btn-secondary inline-flex items-center gap-2"
                onClick={() => deactivateMutation.mutate()}
                disabled={deactivateMutation.isPending}
                title={t('workflows.ui.status.deactivate')}
              >
                <Power className="w-4 h-4" />
                {deactivateMutation.isPending ? t('workflows.ui.status.action_loading') : t('workflows.ui.status.deactivate')}
              </button>
            ) : (
              <button
                type="button"
                className="btn-primary inline-flex items-center gap-2"
                onClick={() => activateMutation.mutate()}
                disabled={activateMutation.isPending}
              >
                <Power className="w-4 h-4" />
                {activateMutation.isPending ? t('workflows.ui.status.action_loading') : t('workflows.ui.status.activate')}
              </button>
            )}
          </div>
        }
      />

      <div className="relative">
        <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={t('workflow.item.loading')} />
        {/* Metadata badges */}
      <div className="flex items-center gap-2 mb-6 flex-wrap">
        <span className={TRIGGER_COLORS[workflow.trigger]}>{t(TRIGGER_LABEL_KEYS[workflow.trigger] ?? workflow.trigger)}</span>
        {workflow.isActive
          ? <span className="badge-green flex items-center gap-1"><CheckCircle className="w-3 h-3" /> {t('workflows.ui.status.active')}</span>
          : <span className="badge-gray flex items-center gap-1"><XCircle className="w-3 h-3" /> {t('workflows.ui.status.inactive')}</span>}
      </div>

      {/* ── Lifecycle pipeline (U10) ── */}
      <section className="card p-5 mb-6">
        <h2 className="text-sm font-semibold mb-4" style={{ color: 'var(--muted)' }}>
          {t('workflows.ui.lifecycle.configured_title')}
        </h2>
        {steps.length === 0 ? (
          <p className="text-sm" style={{ color: 'var(--muted)' }}>
            {t('workflows.ui.lifecycle.configured_empty')}
          </p>
        ) : (
          <LifecyclePipeline steps={steps} t={t} />
        )}
      </section>

      {/* ── Steps list ── */}
      <h2 className="text-base font-semibold mb-3" style={{ color: 'var(--text)' }}>
        {t('workflows.ui.detail.steps_title')} ({steps.length})
      </h2>

      {steps.length === 0 ? (
        <EmptyState icon={GitBranch} title={t('workflows.ui.detail.empty_steps_title')} description={t('workflows.ui.detail.empty_steps_description')} />
      ) : (
        <div className="list-workflow-step space-y-3">
          {steps.map((step) => (
            <div key={step.id} className="item-workflow-step card p-4 flex gap-4 items-start">
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
                  <span className="badge-gray text-xs">
                    {t(`workflows.ui.lifecycle.transition.${step.transitionMode}`)}
                  </span>
                </div>

                <div className="flex flex-wrap gap-2 text-xs">
                  <span className="badge-gray">{t('workflows.ui.detail.output_label')} : {step.outputKey}</span>
                </div>

                {step.actions.length > 0 && (
                  <div className="mt-2 flex flex-wrap gap-2 text-xs">
                    <span style={{ color: 'var(--muted)' }}>{t('workflows.ui.detail.actions_label')} :</span>
                    {step.actions.map(({ id, agentAction, createWithTicket }) => (
                      <span key={id} className="badge-blue flex items-center gap-1">
                        {agentAction.label}
                        {createWithTicket && (
                          <span className="badge-gray text-[10px]">{t('workflows.ui.detail.create_with_ticket')}</span>
                        )}
                      </span>
                    ))}
                  </div>
                )}

                {step.condition && (
                  <div className="mt-2 flex items-start gap-1.5 text-xs" style={{ color: 'var(--muted)' }}>
                    <AlertTriangle className="w-3.5 h-3.5 text-yellow-500 flex-shrink-0 mt-0.5" />
                    <span>{t('workflows.ui.detail.condition_label')} : {step.condition}</span>
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

      <Modal open={editOpen} onClose={() => setEditOpen(false)} title={t('workflows.ui.form.modal_edit_title')}>
        <WorkflowForm
          initial={{ name: workflow.name, description: workflow.description ?? undefined, trigger: workflow.trigger }}
          onSubmit={(data) => updateMutation.mutate(data)}
          loading={updateMutation.isPending}
          onCancel={() => setEditOpen(false)}
          t={t}
        />
      </Modal>
      </div>
    </>
  )
}

// ─── Page router ───────────────────────────────────────────────────────────────

/**
 * Workflows management page — routes to workflows list and workflow detail views.
 */
export default function WorkflowsPage() {
  return (
    <Routes>
      <Route index element={<WorkflowsList />} />
      <Route path=":id" element={<WorkflowDetail />} />
    </Routes>
  )
}
