import { useState } from 'react'
import { Routes, Route, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, FolderKanban, Pencil, Trash2 } from 'lucide-react'
import { projectsApi } from '@/api/projects'
import type { ProjectPayload } from '@/api/projects'
import type { Module } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'
import ProjectDetailPage from './ProjectDetailPage'

function fmt(date: string) {
  return new Date(date).toLocaleDateString('fr-FR', { year: 'numeric', month: 'short', day: 'numeric' })
}

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
  const [name, setName] = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ name, description: description || undefined }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder="Mon projet" />
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

// ─── Projects List ────────────────────────────────────────────────────────────

function ProjectsList() {
  const navigate = useNavigate()
  const qc = useQueryClient()

  const [createOpen, setCreateOpen] = useState(false)
  const [editTarget, setEditTarget] = useState<{ id: string; name: string; description: string | null } | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<{ id: string; name: string } | null>(null)

  const { data: projects, isLoading, error, refetch } = useQuery({
    queryKey: ['projects'],
    queryFn: projectsApi.list,
  })

  const createMutation = useMutation({
    mutationFn: projectsApi.create,
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['projects'] }); setCreateOpen(false) },
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
        action={
          <button className="btn-primary" onClick={() => setCreateOpen(true)}>
            <Plus className="w-4 h-4" /> Nouveau projet
          </button>
        }
      />

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
            <div key={project.id} className="card p-5 flex flex-col gap-3 hover:shadow-md transition-shadow">
              <div className="flex items-start justify-between gap-2">
                <button
                  onClick={() => navigate(`/projects/${project.id}`)}
                  className="text-left font-semibold text-gray-900 hover:text-brand-600 transition-colors"
                >
                  {project.name}
                </button>
                <div className="flex gap-1 flex-shrink-0">
                  <button onClick={() => setEditTarget(project)} className="p-1.5 text-gray-400 hover:text-gray-600" title="Modifier">
                    <Pencil className="w-4 h-4" />
                  </button>
                  <button onClick={() => setDeleteTarget(project)} className="p-1.5 text-gray-400 hover:text-red-500" title="Supprimer">
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              </div>
              {project.description && <p className="text-sm text-gray-500 line-clamp-2">{project.description}</p>}
              <div className="mt-auto flex items-center justify-between text-xs text-gray-400">
                <span>
                  {typeof project.modules === 'number'
                    ? `${project.modules} module${project.modules !== 1 ? 's' : ''}`
                    : `${(project.modules as Module[]).length} modules`}
                </span>
                <span>{fmt(project.createdAt)}</span>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Nouveau projet">
        <ProjectForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} />
      </Modal>

      <Modal open={!!editTarget} onClose={() => setEditTarget(null)} title="Modifier le projet">
        {editTarget && (
          <ProjectForm
            initial={{ name: editTarget.name, description: editTarget.description ?? '' }}
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
