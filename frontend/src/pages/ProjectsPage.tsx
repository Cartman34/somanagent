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
import { translationsApi } from '@/api/translations'
import type { ProjectPayload } from '@/api/projects'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'
import ProjectDetailPage from './ProjectDetailPage'

function fmt(date: string) {
  return new Date(date).toLocaleDateString('fr-FR', { year: 'numeric', month: 'short', day: 'numeric' })
}

// ─── Project Form ─────────────────────────────────────────────────────────────

function ProjectForm({
  initial,
  teams,
  workflows,
  i18n,
  onSubmit,
  loading,
  onCancel,
}: {
  initial?: Partial<ProjectPayload>
  teams: { id: string; name: string }[]
  workflows: { id: string; name: string; isActive: boolean }[]
  i18n: Record<string, string>
  onSubmit: (d: ProjectPayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const [name, setName] = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [teamId, setTeamId] = useState(initial?.teamId ?? '')
  const [workflowId, setWorkflowId] = useState(initial?.workflowId ?? '')
  const availableWorkflows = workflows.filter((workflow) => workflow.isActive || workflow.id === workflowId)

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ name, description: description || undefined, teamId: teamId || null, workflowId }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder="Mon projet" />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea className="input resize-none" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">{i18n['projects.ui.form.team_label'] ?? 'projects.ui.form.team_label'}</label>
        <select className="input" value={teamId} onChange={(e) => setTeamId(e.target.value)}>
          <option value="">{i18n['projects.ui.form.no_team_option'] ?? 'projects.ui.form.no_team_option'}</option>
          {teams.map((team) => (
            <option key={team.id} value={team.id}>{team.name}</option>
          ))}
        </select>
        <p className="mt-1 text-xs text-gray-500">
          {i18n['projects.ui.form.team_hint'] ?? 'projects.ui.form.team_hint'}
        </p>
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Workflow *</label>
        <select className="input" value={workflowId} onChange={(e) => setWorkflowId(e.target.value)} required>
          <option value="" disabled>— Sélectionner un workflow —</option>
          {availableWorkflows.map((workflow) => (
            <option key={workflow.id} value={workflow.id}>{workflow.name}</option>
          ))}
        </select>
        <p className="mt-1 text-xs text-gray-500">
          Workflow utilisé par le projet pour la progression et la résolution automatique des tâches.
        </p>
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Annuler</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? 'Enregistrement…' : 'Enregistrer'}</button>
      </div>
    </form>
  )
}

// ─── Projects List ────────────────────────────────────────────────────────────

function ProjectsList() {
  const navigate = useNavigate()
  const qc = useQueryClient()

  const [createOpen, setCreateOpen] = useState(false)
  const [editTarget, setEditTarget] = useState<{ id: string; name: string; description: string | null; teamId?: string | null; workflowId?: string | null } | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<{ id: string; name: string } | null>(null)

  const { data: projects, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['projects'],
    queryFn: projectsApi.list,
  })

  const { data: teams = [] } = useQuery({
    queryKey: ['teams'],
    queryFn: teamsApi.list,
  })

  const { data: workflows = [] } = useQuery({
    queryKey: ['workflows'],
    queryFn: workflowsApi.list,
    select: (items) => items.map((workflow) => ({ id: workflow.id, name: workflow.name, isActive: workflow.isActive })),
  })

  const { data: formI18n } = useQuery({
    queryKey: ['ui-translations', 'projects-form'],
    queryFn: () => translationsApi.list([
      'projects.ui.form.team_label',
      'projects.ui.form.no_team_option',
      'projects.ui.form.team_hint',
      'common.action.refresh',
    ]),
  })

  const tt = (key: string) => formI18n?.translations[key] ?? key

  const createMutation = useMutation({
    mutationFn: projectsApi.create,
    onSuccess: (project) => {
      qc.invalidateQueries({ queryKey: ['projects'] })
      setCreateOpen(false)

      if (!project.team) {
        navigate(`/projects/${project.id}?tab=general`)
      }
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
        title="Projets"
        description="Gérez vos projets logiciels et leurs modules."
        onRefresh={() => qc.invalidateQueries({ queryKey: ['projects'] })}
        refreshTitle={tt('common.action.refresh')}
        action={
          <button className="btn-primary" onClick={() => setCreateOpen(true)}>
            <Plus className="w-4 h-4" /> Nouveau projet
          </button>
        }
      />

      <div className="relative">
        <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={tt('project.list.loading')} />

        {projects?.length === 0 ? (
          <EmptyState
            icon={FolderKanban}
            title="Aucun projet"
            description="Créez votre premier projet pour gérer des modules et des workflows."
            action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> Nouveau projet</button>}
          />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {projects?.map((project) => (
              <div
                key={project.id}
                role="button"
                tabIndex={0}
                onClick={() => navigate(`/projects/${project.id}`)}
                onKeyDown={(e) => e.key === 'Enter' && navigate(`/projects/${project.id}`)}
                className="card p-5 flex flex-col gap-3 text-left hover:shadow-md transition-shadow cursor-pointer"
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
                        })
                      }}
                      className="p-1.5 text-gray-400 hover:text-gray-600"
                      title="Modifier"
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
                      title="Supprimer"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                </div>
                {project.description && <p className="text-sm text-gray-500 line-clamp-2">{project.description}</p>}
                <div className="mt-auto flex items-center justify-end text-xs text-gray-400">
                  <span>{fmt(project.createdAt)}</span>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Nouveau projet">
          <ProjectForm
            teams={teams}
            workflows={workflows}
            i18n={formI18n?.translations ?? {}}
            onSubmit={(d) => createMutation.mutate(d)}
            loading={createMutation.isPending}
          onCancel={() => setCreateOpen(false)}
        />
      </Modal>

      <Modal open={!!editTarget} onClose={() => setEditTarget(null)} title="Modifier le projet">
        {editTarget && (
          <ProjectForm
            initial={{ name: editTarget.name, description: editTarget.description ?? '', teamId: editTarget.teamId ?? null, workflowId: editTarget.workflowId ?? null }}
            teams={teams}
            workflows={workflows}
            i18n={formI18n?.translations ?? {}}
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
        message={`Supprimer le projet "${deleteTarget?.name}" ? Cette action est irréversible.`}
        loading={deleteMutation.isPending}
      />
    </>
  )
}

// ─── Page router ──────────────────────────────────────────────────────────────

export default function ProjectsPage() {
  return (
    <Routes>
      <Route index element={<ProjectsList />} />
      <Route path=":id/*" element={<ProjectDetailPage />} />
    </Routes>
  )
}
