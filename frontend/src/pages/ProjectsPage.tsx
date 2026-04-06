/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState } from 'react'
import { Routes, Route, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, FolderKanban, Pencil, Trash2 } from 'lucide-react'
import { projectsApi } from '@/api/projects'
import { teamsApi } from '@/api/teams'
import { workflowsApi } from '@/api/workflows'
import type { ProjectPayload } from '@/api/projects'
import type { ProjectDispatchMode } from '@/types'
import { useTranslation } from '@/hooks/useTranslation'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'
import ProjectDetailPage from './ProjectDetailPage'

const PROJECTS_PAGE_TRANSLATION_KEYS = [
  'project.page.title',
  'project.page.description',
  'project.form.name_label',
  'project.form.name_placeholder',
  'project.form.description_label',
  'project.form.team_label',
  'project.form.team_placeholder',
  'project.form.workflow_label',
  'project.form.workflow_placeholder',
  'project.form.workflow_hint',
  'project.form.dispatch_mode_label',
  'project.form.dispatch_mode_hint',
  'project.form.dispatch_mode_auto',
  'project.form.dispatch_mode_manual',
  'common.action.cancel',
  'common.action.refresh',
  'common.action.save',
  'common.action.saving',
  'project.list.loading',
  'project.list.empty_title',
  'project.list.empty_description',
  'project.list.new_button',
  'project.list.edit_button',
  'project.list.delete_button',
  'project.list.delete_confirm',
  'project.list.modal_create_title',
  'project.list.modal_edit_title',
] as const

// ─── Project Form ─────────────────────────────────────────────────────────────

function ProjectForm({
  initial,
  onSubmit,
  loading,
  onCancel,
}: {
  initial?: Partial<ProjectPayload>
  onSubmit: (d: ProjectPayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const { t } = useTranslation(PROJECTS_PAGE_TRANSLATION_KEYS)
  const [name, setName] = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [teamId, setTeamId] = useState(initial?.teamId ?? '')
  const [workflowId, setWorkflowId] = useState(initial?.workflowId ?? '')
  const [dispatchMode, setDispatchMode] = useState<ProjectDispatchMode>(initial?.dispatchMode ?? 'auto')

  const { data: teams = [] } = useQuery({
    queryKey: ['teams'],
    queryFn: teamsApi.list,
  })

  const { data: workflows = [] } = useQuery({
    queryKey: ['workflows'],
    queryFn: workflowsApi.list,
    select: (items) => items.map((workflow) => ({ id: workflow.id, name: workflow.name, isActive: workflow.isActive })),
  })

  const availableWorkflows = workflows.filter((workflow) => workflow.isActive || workflow.id === workflowId)

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        onSubmit({ name, description: description || undefined, teamId, workflowId, dispatchMode })
      }}
      className="space-y-5"
    >
      <div className="grid gap-4 md:grid-cols-2">
        <div className="md:col-span-2">
          <label className="block text-sm font-medium text-gray-700 mb-1">{t('project.form.name_label')} *</label>
          <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder={t('project.form.name_placeholder')} />
        </div>
        <div className="md:col-span-2">
          <label className="block text-sm font-medium text-gray-700 mb-1">{t('project.form.description_label')}</label>
          <textarea className="input resize-none" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">{t('project.form.team_label')} *</label>
          <select className="input" value={teamId} onChange={(e) => setTeamId(e.target.value)} required>
            <option value="" disabled>{t('project.form.team_placeholder')}</option>
            {teams.map((team) => (
              <option key={team.id} value={team.id}>{team.name}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">{t('project.form.workflow_label')} *</label>
          <select className="input" value={workflowId} onChange={(e) => setWorkflowId(e.target.value)} required>
            <option value="" disabled>{t('project.form.workflow_placeholder')}</option>
            {availableWorkflows.map((workflow) => (
              <option key={workflow.id} value={workflow.id}>{workflow.name}</option>
            ))}
          </select>
          <p className="mt-1 text-xs text-gray-500">
            {t('project.form.workflow_hint')}
          </p>
        </div>
        <div className="md:col-span-2">
          <label className="block text-sm font-medium text-gray-700 mb-1">{t('project.form.dispatch_mode_label')}</label>
          <select
            className="input"
            value={dispatchMode}
            onChange={(e) => setDispatchMode(e.target.value as ProjectDispatchMode)}
          >
            <option value="auto">{t('project.form.dispatch_mode_auto')}</option>
            <option value="manual">{t('project.form.dispatch_mode_manual')}</option>
          </select>
          <p className="mt-1 text-xs text-gray-500">
            {t('project.form.dispatch_mode_hint')}
          </p>
        </div>
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">{t('common.action.cancel')}</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? t('common.action.saving') : t('common.action.save')}</button>
      </div>
    </form>
  )
}

// ─── Projects List ────────────────────────────────────────────────────────────

function ProjectsList() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { t, formatDate } = useTranslation(PROJECTS_PAGE_TRANSLATION_KEYS)

  const [createOpen, setCreateOpen] = useState(false)
  const [editTarget, setEditTarget] = useState<{ id: string; name: string; description: string | null; teamId?: string | null; workflowId?: string | null; dispatchMode?: ProjectDispatchMode } | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<{ id: string; name: string } | null>(null)

  const { data: projects, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['projects'],
    queryFn: projectsApi.list,
  })

  const createMutation = useMutation({
    mutationFn: projectsApi.create,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['projects'] })
      setCreateOpen(false)
    },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: ProjectPayload }) => projectsApi.update(id, data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['projects'] }); setEditTarget(null) },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => projectsApi.delete(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['projects'] }); setDeleteTarget(null) },
  })

  if (isLoading) return <PageSpinner />
  if (error) return <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />

  return (
    <>
      <PageHeader
        title={t('project.page.title')}
        description={t('project.page.description')}
        onRefresh={() => qc.invalidateQueries({ queryKey: ['projects'] })}
        refreshTitle={t('common.action.refresh')}
        action={
          <button className="btn-primary" onClick={() => setCreateOpen(true)}>
            <Plus className="w-4 h-4" /> {t('project.list.new_button')}
          </button>
        }
      />

      <div className="relative">
        <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={t('project.list.loading')} />

        {projects?.length === 0 ? (
          <EmptyState
            icon={FolderKanban}
            title={t('project.list.empty_title')}
            description={t('project.list.empty_description')}
            action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> {t('project.list.new_button')}</button>}
          />
        ) : (
          <div className="list-project grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {projects?.map((project) => (
              <div
                key={project.id}
                role="button"
                tabIndex={0}
                onClick={() => navigate(`/projects/${project.id}`)}
                onKeyDown={(e) => e.key === 'Enter' && navigate(`/projects/${project.id}`)}
                className="item-project card p-5 flex flex-col gap-3 text-left hover:shadow-md transition-shadow cursor-pointer"
              >
                <div className="flex items-start justify-between gap-2">
                  <span className="font-semibold text-gray-900 transition-colors">
                    {project.name}
                  </span>
                  <div className="flex gap-1 flex-shrink-0">
                    <button
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation()
                        setEditTarget({
                          id: project.id,
                          name: project.name,
                          description: project.description,
                          teamId: project.team?.id ?? null,
                          workflowId: project.workflow?.id ?? null,
                          dispatchMode: project.dispatchMode,
                        })
                      }}
                      className="p-1.5 text-gray-400 hover:text-gray-600"
                      title={t('project.list.edit_button')}
                    >
                      <Pencil className="w-4 h-4" />
                    </button>
                    <button
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation()
                        setDeleteTarget(project)
                      }}
                      className="p-1.5 text-gray-400 hover:text-red-500"
                      title={t('project.list.delete_button')}
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                </div>
                {project.description && <p className="text-sm text-gray-500 line-clamp-2">{project.description}</p>}
                <div className="mt-auto flex items-center justify-end text-xs text-gray-400">
                  <span>{formatDate(project.createdAt)}</span>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title={t('project.list.modal_create_title')}>
        <ProjectForm
          onSubmit={(d) => createMutation.mutate(d)}
          loading={createMutation.isPending}
          onCancel={() => setCreateOpen(false)}
        />
      </Modal>

      <Modal open={!!editTarget} onClose={() => setEditTarget(null)} title={t('project.list.modal_edit_title')}>
        {editTarget && (
          <ProjectForm
            initial={{ name: editTarget.name, description: editTarget.description ?? '', teamId: editTarget.teamId ?? null, workflowId: editTarget.workflowId ?? null, dispatchMode: editTarget.dispatchMode }}
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
        message={t('project.list.delete_confirm', { name: deleteTarget?.name ?? '' })}
        loading={deleteMutation.isPending}
      />
    </>
  )
}

// ─── Page router ──────────────────────────────────────────────────────────────

/**
 * Projects page — routes to project list and project detail views.
 */
export default function ProjectsPage() {
  return (
    <Routes>
      <Route index element={<ProjectsList />} />
      <Route path=":id/*" element={<ProjectDetailPage />} />
    </Routes>
  )
}
