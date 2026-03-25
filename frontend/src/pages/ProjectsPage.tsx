import { useState } from 'react'
import { Routes, Route, useNavigate, useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, FolderKanban, ArrowLeft, Pencil, Trash2, Globe, Code2 } from 'lucide-react'
import { projectsApi } from '@/api/projects'
import type { ProjectPayload, ModulePayload } from '@/api/projects'
import type { Module } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'

function fmt(date: string) {
  return new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
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
        <label className="block text-sm font-medium text-gray-700 mb-1">Name *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder="My project" />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea className="input resize-none" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Cancel</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? 'Saving…' : 'Save'}</button>
      </div>
    </form>
  )
}

// ─── Module Form ──────────────────────────────────────────────────────────────

function ModuleForm({
  initial,
  onSubmit,
  loading,
  onCancel,
}: {
  initial?: Partial<ModulePayload>
  onSubmit: (d: ModulePayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const [name, setName] = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [repositoryUrl, setRepositoryUrl] = useState(initial?.repositoryUrl ?? '')
  const [stack, setStack] = useState(initial?.stack ?? '')
  const [status, setStatus] = useState<'active' | 'archived'>(initial?.status ?? 'active')

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        onSubmit({ name, description: description || undefined, repositoryUrl: repositoryUrl || undefined, stack: stack || undefined, status })
      }}
      className="space-y-4"
    >
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Name *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder="PHP API" />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea className="input resize-none" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Tech stack</label>
          <input className="input" value={stack} onChange={(e) => setStack(e.target.value)} placeholder="PHP 8.4, Symfony" />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Status</label>
          <select className="input" value={status} onChange={(e) => setStatus(e.target.value as 'active' | 'archived')}>
            <option value="active">Active</option>
            <option value="archived">Archived</option>
          </select>
        </div>
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Repository URL</label>
        <input className="input" value={repositoryUrl} onChange={(e) => setRepositoryUrl(e.target.value)} placeholder="https://github.com/…" />
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Cancel</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? 'Saving…' : 'Save'}</button>
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
        title="Projects"
        description="Manage your software projects and their modules."
        action={
          <button className="btn-primary" onClick={() => setCreateOpen(true)}>
            <Plus className="w-4 h-4" /> New project
          </button>
        }
      />

      {projects?.length === 0 ? (
        <EmptyState
          icon={FolderKanban}
          title="No projects yet"
          description="Create your first project to start managing modules and workflows."
          action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> New project</button>}
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
                  <button onClick={() => setEditTarget(project)} className="p-1.5 text-gray-400 hover:text-gray-600" title="Edit">
                    <Pencil className="w-4 h-4" />
                  </button>
                  <button onClick={() => setDeleteTarget(project)} className="p-1.5 text-gray-400 hover:text-red-500" title="Delete">
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

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="New project">
        <ProjectForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} />
      </Modal>

      <Modal open={!!editTarget} onClose={() => setEditTarget(null)} title="Edit project">
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
        message={`Delete "${deleteTarget?.name}"? This action cannot be undone.`}
        loading={deleteMutation.isPending}
      />
    </>
  )
}

// ─── Project Detail ───────────────────────────────────────────────────────────

function ProjectDetail() {
  const { id } = useParams<{ id: string }>()
  const qc = useQueryClient()

  const [addOpen, setAddOpen] = useState(false)
  const [editModule, setEditModule] = useState<Module | null>(null)
  const [deleteModule, setDeleteModule] = useState<Module | null>(null)

  const { data: project, isLoading, error, refetch } = useQuery({
    queryKey: ['projects', id],
    queryFn: () => projectsApi.get(id!),
    enabled: !!id,
  })

  const addMutation = useMutation({
    mutationFn: (d: ModulePayload) => projectsApi.addModule(id!, d),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['projects', id] }); setAddOpen(false) },
  })

  const updateMutation = useMutation({
    mutationFn: ({ mid, d }: { mid: string; d: ModulePayload }) => projectsApi.updateModule(mid, d),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['projects', id] }); setEditModule(null) },
  })

  const deleteMutation = useMutation({
    mutationFn: (mid: string) => projectsApi.deleteModule(mid),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['projects', id] }); setDeleteModule(null) },
  })

  if (isLoading) return <PageSpinner />
  if (error || !project) return <ErrorMessage message={(error as Error)?.message ?? 'Project not found'} onRetry={() => refetch()} />

  const modules = Array.isArray(project.modules) ? (project.modules as Module[]) : []

  return (
    <>
      <Link to="/projects" className="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 mb-4">
        <ArrowLeft className="w-4 h-4" /> Projects
      </Link>

      <PageHeader
        title={project.name}
        description={project.description ?? undefined}
        action={
          <button className="btn-primary" onClick={() => setAddOpen(true)}>
            <Plus className="w-4 h-4" /> Add module
          </button>
        }
      />

      <h2 className="text-base font-semibold text-gray-900 mb-3">Modules ({modules.length})</h2>

      {modules.length === 0 ? (
        <EmptyState
          icon={Code2}
          title="No modules yet"
          description="Add sub-softwares to this project (PHP API, Android client, etc.)"
          action={<button className="btn-primary" onClick={() => setAddOpen(true)}><Plus className="w-4 h-4" /> Add module</button>}
        />
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {modules.map((mod) => (
            <div key={mod.id} className="card p-4 flex flex-col gap-2">
              <div className="flex items-start justify-between gap-2">
                <span className="font-medium text-gray-900">{mod.name}</span>
                <div className="flex gap-1 flex-shrink-0">
                  <button onClick={() => setEditModule(mod)} className="p-1.5 text-gray-400 hover:text-gray-600" title="Edit">
                    <Pencil className="w-3.5 h-3.5" />
                  </button>
                  <button onClick={() => setDeleteModule(mod)} className="p-1.5 text-gray-400 hover:text-red-500" title="Delete">
                    <Trash2 className="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
              {mod.description && <p className="text-xs text-gray-500">{mod.description}</p>}
              {mod.stack && <span className="badge-blue self-start">{mod.stack}</span>}
              {mod.repositoryUrl && (
                <a href={mod.repositoryUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs text-brand-600 hover:underline">
                  <Globe className="w-3 h-3" /> Repository
                </a>
              )}
              <div className="mt-auto pt-1">
                <span className={mod.status === 'active' ? 'badge-green' : 'badge-gray'}>{mod.status}</span>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={addOpen} onClose={() => setAddOpen(false)} title="Add module">
        <ModuleForm onSubmit={(d) => addMutation.mutate(d)} loading={addMutation.isPending} onCancel={() => setAddOpen(false)} />
      </Modal>

      <Modal open={!!editModule} onClose={() => setEditModule(null)} title="Edit module">
        {editModule && (
          <ModuleForm
            initial={{ name: editModule.name, description: editModule.description ?? '', repositoryUrl: editModule.repositoryUrl ?? '', stack: editModule.stack ?? '', status: editModule.status }}
            onSubmit={(d) => updateMutation.mutate({ mid: editModule.id, d })}
            loading={updateMutation.isPending}
            onCancel={() => setEditModule(null)}
          />
        )}
      </Modal>

      <ConfirmDialog
        open={!!deleteModule}
        onClose={() => setDeleteModule(null)}
        onConfirm={() => deleteModule && deleteMutation.mutate(deleteModule.id)}
        message={`Delete module "${deleteModule?.name}"?`}
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
      <Route path=":id" element={<ProjectDetail />} />
    </Routes>
  )
}
