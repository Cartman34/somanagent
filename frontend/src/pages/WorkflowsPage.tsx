/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState, type ReactNode } from 'react'
import { Routes, Route, useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Plus, GitBranch, ArrowLeft, CheckCircle, XCircle,
  Clock, Play, SkipForward, AlertCircle, Lock, ShieldCheck, FileEdit,
  ChevronRight, User, BookOpen, AlertTriangle,
} from 'lucide-react'
import { workflowsApi } from '@/api/workflows'
import { translationsApi } from '@/api/translations'
import type { WorkflowPayload } from '@/api/workflows'
import type { WorkflowStep, WorkflowStatus } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import PageHeader from '@/components/ui/PageHeader'

// ─── Constants ────────────────────────────────────────────────────────────────

const WORKFLOWS_UI_TRANSLATION_KEYS = [
  'common.action.cancel',
  'workflows.ui.form.trigger_label',
  'workflows.ui.form.immutable_hint',
  'workflows.ui.form.name_label',
  'workflows.ui.form.name_placeholder',
  'workflows.ui.form.description_label',
  'workflows.ui.form.submit_loading',
  'workflows.ui.form.submit_create',
  'workflows.ui.form.modal_create_title',
  'workflows.ui.trigger.manual',
  'workflows.ui.trigger.vcs_event',
  'workflows.ui.trigger.scheduled',
  'workflows.ui.status.draft',
  'workflows.ui.status.validated',
  'workflows.ui.status.locked',
  'workflows.ui.status.immutable_badge',
  'workflows.ui.status.immutable_notice',
  'workflows.ui.status.active',
  'workflows.ui.status.inactive',
  'workflows.ui.lifecycle.new',
  'workflows.ui.lifecycle.ready',
  'workflows.ui.lifecycle.approved',
  'workflows.ui.lifecycle.planning',
  'workflows.ui.lifecycle.graphic_design',
  'workflows.ui.lifecycle.development',
  'workflows.ui.lifecycle.code_review',
  'workflows.ui.lifecycle.done',
  'workflows.ui.lifecycle.conditional',
  'workflows.ui.lifecycle.configured_title',
  'workflows.ui.lifecycle.configured_empty',
  'workflows.ui.page.description',
  'workflows.ui.page.empty_title',
  'workflows.ui.page.create_button',
  'workflows.ui.page.empty_description',
  'workflows.ui.detail.not_found',
  'workflows.ui.detail.back_link',
  'workflows.ui.detail.team_label',
  'workflows.ui.detail.output_label',
  'workflows.ui.detail.condition_label',
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

/** Ordered story lifecycle stages displayed in the visual pipeline. */
const LIFECYCLE_STAGES: { key: string; labelKey: string }[] = [
  { key: 'new',           labelKey: 'workflows.ui.lifecycle.new' },
  { key: 'ready',         labelKey: 'workflows.ui.lifecycle.ready' },
  { key: 'approved',      labelKey: 'workflows.ui.lifecycle.approved' },
  { key: 'planning',      labelKey: 'workflows.ui.lifecycle.planning' },
  { key: 'graphic_design',labelKey: 'workflows.ui.lifecycle.graphic_design' },
  { key: 'development',   labelKey: 'workflows.ui.lifecycle.development' },
  { key: 'code_review',   labelKey: 'workflows.ui.lifecycle.code_review' },
  { key: 'done',          labelKey: 'workflows.ui.lifecycle.done' },
]

const STATUS_CONFIG: Record<WorkflowStatus, { labelKey: string; badge: string; icon: typeof ShieldCheck }> = {
  draft:     { labelKey: 'workflows.ui.status.draft',     badge: 'badge-gray',   icon: FileEdit    },
  validated: { labelKey: 'workflows.ui.status.validated', badge: 'badge-green',  icon: ShieldCheck },
  locked:    { labelKey: 'workflows.ui.status.locked',    badge: 'badge-orange', icon: Lock        },
}

function useWorkflowUiTranslations() {
  return useQuery({
    queryKey: ['ui-translations', 'workflows-page'],
    queryFn: () => translationsApi.list([...WORKFLOWS_UI_TRANSLATION_KEYS]),
  })
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
function LifecyclePipeline({ steps, tt }: { steps: WorkflowStep[]; tt: (key: string) => string }) {
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
                  {tt(stage.labelKey)}
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
                        {tt('workflows.ui.lifecycle.conditional')}
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
 * Create form for a Workflow.
 * Rendered inside a Modal and persisted as an immutable definition.
 */
function WorkflowForm({
  onSubmit,
  loading,
  onCancel,
  tt,
}: {
  onSubmit: (d: WorkflowPayload) => void
  loading: boolean
  onCancel: () => void
  tt: (key: string) => string
}) {
  const [name,        setName]       = useState('')
  const [description, setDescription]= useState('')
  const [trigger,     setTrigger]    = useState<WorkflowPayload['trigger']>('manual')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ name, description: description || undefined, trigger }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{tt('workflows.ui.form.name_label')} *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder={tt('workflows.ui.form.name_placeholder')} />
      </div>
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{tt('workflows.ui.form.description_label')}</label>
        <textarea className="input resize-none" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{tt('workflows.ui.form.trigger_label')}</label>
        <select className="input" value={trigger} onChange={(e) => setTrigger(e.target.value as WorkflowPayload['trigger'])}>
          <option value="manual">{tt('workflows.ui.trigger.manual')}</option>
          <option value="vcs_event">{tt('workflows.ui.trigger.vcs_event')}</option>
          <option value="scheduled">{tt('workflows.ui.trigger.scheduled')}</option>
        </select>
      </div>
      <p className="text-xs" style={{ color: 'var(--muted)' }}>
        {tt('workflows.ui.form.immutable_hint')}
      </p>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">{tt('common.action.cancel')}</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? tt('workflows.ui.form.submit_loading') : tt('workflows.ui.form.submit_create')}</button>
      </div>
    </form>
  )
}

// ─── Workflows List ────────────────────────────────────────────────────────────

/** Grid of workflow cards with CRUD actions. */
function WorkflowsList() {
  const qc = useQueryClient()
  const [createOpen,   setCreateOpen]   = useState(false)
  const { data: i18n } = useWorkflowUiTranslations()

  const { data: workflows, isLoading, error, refetch } = useQuery({
    queryKey: ['workflows'],
    queryFn: workflowsApi.list,
  })

  const tt = (key: string) => i18n?.translations[key] ?? key

  const createMutation = useMutation({
    mutationFn: workflowsApi.create,
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['workflows'] }); setCreateOpen(false) },
  })

  if (isLoading) return <PageSpinner />
  if (error)     return <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />

  return (
    <>
      <PageHeader
        title="Workflows"
        description={tt('workflows.ui.page.description')}
        action={
          <button className="btn-primary" onClick={() => setCreateOpen(true)}>
            <Plus className="w-4 h-4" /> {tt('workflows.ui.page.create_button')}
          </button>
        }
      />

      {workflows?.length === 0 ? (
        <EmptyState
          icon={GitBranch}
          title={tt('workflows.ui.page.empty_title')}
          description={tt('workflows.ui.page.empty_description')}
          action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> {tt('workflows.ui.page.create_button')}</button>}
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
                  <span className="badge-gray flex items-center gap-1 flex-shrink-0">
                    <Lock className="w-3 h-3" />
                    {tt('workflows.ui.status.immutable_badge')}
                  </span>
                </div>

                {wf.description && <p className="text-sm line-clamp-2" style={{ color: 'var(--muted)' }}>{wf.description}</p>}

                <div className="flex items-center gap-2 flex-wrap">
                  <span className={TRIGGER_COLORS[wf.trigger]}>{tt(TRIGGER_LABEL_KEYS[wf.trigger] ?? wf.trigger)}</span>
                  {wf.team && <span className="badge-gray">{wf.team.name}</span>}
                  <span className={`${sc.badge} flex items-center gap-1`}>
                    <StatusIcon className="w-3 h-3" />
                    {tt(sc.labelKey)}
                  </span>
                </div>

                <div className="mt-auto flex items-center justify-between text-xs" style={{ color: 'var(--muted)' }}>
                  <span>
                    {typeof wf.steps === 'number'
                      ? `${wf.steps} ${tt(wf.steps !== 1 ? 'workflows.ui.count.step_other' : 'workflows.ui.count.step_one')}`
                      : `${wf.steps.length} ${tt(wf.steps.length !== 1 ? 'workflows.ui.count.step_other' : 'workflows.ui.count.step_one')}`}
                  </span>
                  <div className="flex items-center gap-1">
                    {wf.isUsable
                      ? <><CheckCircle className="w-3.5 h-3.5 text-green-500" /> {tt('workflows.ui.status.active')}</>
                      : <><XCircle    className="w-3.5 h-3.5" style={{ color: 'var(--muted)' }} /> {tt('workflows.ui.status.inactive')}</>}
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title={tt('workflows.ui.form.modal_create_title')}>
        <WorkflowForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} tt={tt} />
      </Modal>
    </>
  )
}

// ─── Workflow Detail ───────────────────────────────────────────────────────────

/**
 * Full detail view for a workflow:
 * - Metadata header with status badge and immutable-definition notice
 * - Visual lifecycle pipeline showing which stages have configured agent steps (U10)
 * - Ordered step list with role, skill, condition, execution output
 */
function WorkflowDetail() {
  const { id }   = useParams<{ id: string }>()
  const { data: i18n } = useWorkflowUiTranslations()

  const { data: workflow, isLoading, error, refetch } = useQuery({
    queryKey: ['workflows', id],
    queryFn:  () => workflowsApi.get(id!),
    enabled:  !!id,
  })

  const tt = (key: string) => i18n?.translations[key] ?? key

  if (isLoading) return <PageSpinner />
  if (error || !workflow) return <ErrorMessage message={(error as Error)?.message ?? tt('workflows.ui.detail.not_found')} onRetry={() => refetch()} />

  const steps   = Array.isArray(workflow.steps) ? [...workflow.steps].sort((a, b) => a.stepOrder - b.stepOrder) : []
  const sc      = STATUS_CONFIG[workflow.status] ?? STATUS_CONFIG.draft
  const StatusIcon = sc.icon

  return (
    <>
      <Link to="/workflows" className="inline-flex items-center gap-1 text-sm mb-4" style={{ color: 'var(--muted)' }}>
        <ArrowLeft className="w-4 h-4" /> {tt('workflows.ui.detail.back_link')}
      </Link>

      {/* Header */}
      <div className="flex items-start justify-between gap-4 mb-4 flex-wrap">
        <div>
          <h1 className="text-xl font-bold" style={{ color: 'var(--text)' }}>{workflow.name}</h1>
          {workflow.description && <p className="text-sm mt-1" style={{ color: 'var(--muted)' }}>{workflow.description}</p>}
        </div>
        <span className="badge-gray flex items-center gap-1.5 flex-shrink-0">
          <Lock className="w-3.5 h-3.5" />
          {tt('workflows.ui.status.immutable_notice')}
        </span>
      </div>

      {/* Metadata badges */}
      <div className="flex items-center gap-2 mb-6 flex-wrap">
        <span className={TRIGGER_COLORS[workflow.trigger]}>{tt(TRIGGER_LABEL_KEYS[workflow.trigger] ?? workflow.trigger)}</span>
        {workflow.team && <span className="badge-gray">{tt('workflows.ui.detail.team_label')} : {workflow.team.name}</span>}
        <span className={`${sc.badge} flex items-center gap-1`}>
          <StatusIcon className="w-3 h-3" />
          {tt(sc.labelKey)}
        </span>
        {workflow.isUsable
          ? <span className="badge-green flex items-center gap-1"><CheckCircle className="w-3 h-3" /> {tt('workflows.ui.status.active')}</span>
          : <span className="badge-gray flex items-center gap-1"><XCircle className="w-3 h-3" /> {tt('workflows.ui.status.inactive')}</span>}
      </div>

      {/* ── Lifecycle pipeline (U10) ── */}
      <section className="card p-5 mb-6">
        <h2 className="text-sm font-semibold mb-4" style={{ color: 'var(--muted)' }}>
          {tt('workflows.ui.lifecycle.configured_title')}
        </h2>
        {steps.length === 0 ? (
          <p className="text-sm" style={{ color: 'var(--muted)' }}>
            {tt('workflows.ui.lifecycle.configured_empty')}
          </p>
        ) : (
          <LifecyclePipeline steps={steps} tt={tt} />
        )}
      </section>

      {/* ── Steps list ── */}
      <h2 className="text-base font-semibold mb-3" style={{ color: 'var(--text)' }}>
        {tt('workflows.ui.detail.steps_title')} ({steps.length})
      </h2>

      {steps.length === 0 ? (
        <EmptyState icon={GitBranch} title={tt('workflows.ui.detail.empty_steps_title')} description={tt('workflows.ui.detail.empty_steps_description')} />
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
                  <span className="badge-gray">{tt('workflows.ui.detail.output_label')} : {step.outputKey}</span>
                </div>

                {step.condition && (
                  <div className="mt-2 flex items-start gap-1.5 text-xs" style={{ color: 'var(--muted)' }}>
                    <AlertTriangle className="w-3.5 h-3.5 text-yellow-500 flex-shrink-0 mt-0.5" />
                    <span>{tt('workflows.ui.detail.condition_label')} : {step.condition}</span>
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
