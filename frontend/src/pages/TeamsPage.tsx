import { useState } from 'react'
import { Routes, Route, useNavigate, useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Users, ArrowLeft, Pencil, Trash2, UserCog } from 'lucide-react'
import { teamsApi } from '@/api/teams'
import type { TeamPayload, RolePayload } from '@/api/teams'
import type { Role } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'

function fmt(date: string) {
  return new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

// ─── Team Form ────────────────────────────────────────────────────────────────

function TeamForm({
  initial,
  onSubmit,
  loading,
  onCancel,
}: {
  initial?: Partial<TeamPayload>
  onSubmit: (d: TeamPayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const [name, setName] = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ name, description: description || undefined }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Name *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder="Web Development Team" />
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

// ─── Role Form ────────────────────────────────────────────────────────────────

function RoleForm({
  initial,
  onSubmit,
  loading,
  onCancel,
}: {
  initial?: Partial<RolePayload>
  onSubmit: (d: RolePayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const [name, setName] = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [skillSlug, setSkillSlug] = useState(initial?.skillSlug ?? '')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ name, description: description || undefined, skillSlug: skillSlug || undefined }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Name *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder="Lead Developer" />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea className="input resize-none" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Skill slug</label>
        <input className="input" value={skillSlug} onChange={(e) => setSkillSlug(e.target.value)} placeholder="code-review" />
        <p className="text-xs text-gray-400 mt-1">Optional — links this role to a skill.</p>
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Cancel</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? 'Saving…' : 'Save'}</button>
      </div>
    </form>
  )
}

// ─── Teams List ───────────────────────────────────────────────────────────────

function TeamsList() {
  const navigate = useNavigate()
  const qc = useQueryClient()

  const [createOpen, setCreateOpen] = useState(false)
  const [editTarget, setEditTarget] = useState<{ id: string; name: string; description: string | null } | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<{ id: string; name: string } | null>(null)

  const { data: teams, isLoading, error, refetch } = useQuery({
    queryKey: ['teams'],
    queryFn: teamsApi.list,
  })

  const createMutation = useMutation({
    mutationFn: teamsApi.create,
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['teams'] }); setCreateOpen(false) },
  })

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: TeamPayload }) => teamsApi.update(id, data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['teams'] }); setEditTarget(null) },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => teamsApi.delete(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['teams'] }); setDeleteTarget(null) },
  })

  if (isLoading) return <PageSpinner />
  if (error) return <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />

  return (
    <>
      <PageHeader
        title="Teams"
        description="Organize your agents into teams with specialized roles."
        action={
          <button className="btn-primary" onClick={() => setCreateOpen(true)}>
            <Plus className="w-4 h-4" /> New team
          </button>
        }
      />

      {teams?.length === 0 ? (
        <EmptyState
          icon={Users}
          title="No teams yet"
          description="Create a team and assign roles to organize your agents."
          action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> New team</button>}
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {teams?.map((team) => (
            <div key={team.id} className="card p-5 flex flex-col gap-3 hover:shadow-md transition-shadow">
              <div className="flex items-start justify-between gap-2">
                <button
                  onClick={() => navigate(`/teams/${team.id}`)}
                  className="text-left font-semibold text-gray-900 hover:text-brand-600 transition-colors"
                >
                  {team.name}
                </button>
                <div className="flex gap-1 flex-shrink-0">
                  <button onClick={() => setEditTarget(team)} className="p-1.5 text-gray-400 hover:text-gray-600" title="Edit">
                    <Pencil className="w-4 h-4" />
                  </button>
                  <button onClick={() => setDeleteTarget(team)} className="p-1.5 text-gray-400 hover:text-red-500" title="Delete">
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              </div>
              {team.description && <p className="text-sm text-gray-500 line-clamp-2">{team.description}</p>}
              <div className="mt-auto flex items-center justify-between text-xs text-gray-400">
                <span>
                  {typeof team.roles === 'number'
                    ? `${team.roles} role${team.roles !== 1 ? 's' : ''}`
                    : `${(team.roles as Role[]).length} roles`}
                </span>
                <span>{fmt(team.createdAt)}</span>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="New team">
        <TeamForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} />
      </Modal>

      <Modal open={!!editTarget} onClose={() => setEditTarget(null)} title="Edit team">
        {editTarget && (
          <TeamForm
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
        message={`Delete team "${deleteTarget?.name}"? This action cannot be undone.`}
        loading={deleteMutation.isPending}
      />
    </>
  )
}

// ─── Team Detail ──────────────────────────────────────────────────────────────

function TeamDetail() {
  const { id } = useParams<{ id: string }>()
  const qc = useQueryClient()

  const [addOpen, setAddOpen] = useState(false)
  const [editRole, setEditRole] = useState<Role | null>(null)
  const [deleteRole, setDeleteRole] = useState<Role | null>(null)

  const { data: team, isLoading, error, refetch } = useQuery({
    queryKey: ['teams', id],
    queryFn: () => teamsApi.get(id!),
    enabled: !!id,
  })

  const addMutation = useMutation({
    mutationFn: (d: RolePayload) => teamsApi.addRole(id!, d),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['teams', id] }); setAddOpen(false) },
  })

  const updateMutation = useMutation({
    mutationFn: ({ rid, d }: { rid: string; d: RolePayload }) => teamsApi.updateRole(rid, d),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['teams', id] }); setEditRole(null) },
  })

  const deleteMutation = useMutation({
    mutationFn: (rid: string) => teamsApi.deleteRole(rid),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['teams', id] }); setDeleteRole(null) },
  })

  if (isLoading) return <PageSpinner />
  if (error || !team) return <ErrorMessage message={(error as Error)?.message ?? 'Team not found'} onRetry={() => refetch()} />

  const roles = Array.isArray(team.roles) ? (team.roles as Role[]) : []

  return (
    <>
      <Link to="/teams" className="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 mb-4">
        <ArrowLeft className="w-4 h-4" /> Teams
      </Link>

      <PageHeader
        title={team.name}
        description={team.description ?? undefined}
        action={
          <button className="btn-primary" onClick={() => setAddOpen(true)}>
            <Plus className="w-4 h-4" /> Add role
          </button>
        }
      />

      <h2 className="text-base font-semibold text-gray-900 mb-3">Roles ({roles.length})</h2>

      {roles.length === 0 ? (
        <EmptyState
          icon={UserCog}
          title="No roles yet"
          description="Add roles to define responsibilities within this team."
          action={<button className="btn-primary" onClick={() => setAddOpen(true)}><Plus className="w-4 h-4" /> Add role</button>}
        />
      ) : (
        <div className="card divide-y divide-gray-100">
          {roles.map((role) => (
            <div key={role.id} className="flex items-center gap-3 px-4 py-3">
              <UserCog className="w-4 h-4 text-gray-400 flex-shrink-0" />
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-gray-900">{role.name}</p>
                {role.description && <p className="text-xs text-gray-500 truncate">{role.description}</p>}
              </div>
              {role.skillSlug && (
                <span className="badge-blue hidden sm:inline-flex">{role.skillSlug}</span>
              )}
              <div className="flex gap-1 flex-shrink-0">
                <button onClick={() => setEditRole(role)} className="p-1.5 text-gray-400 hover:text-gray-600" title="Edit">
                  <Pencil className="w-3.5 h-3.5" />
                </button>
                <button onClick={() => setDeleteRole(role)} className="p-1.5 text-gray-400 hover:text-red-500" title="Delete">
                  <Trash2 className="w-3.5 h-3.5" />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={addOpen} onClose={() => setAddOpen(false)} title="Add role">
        <RoleForm onSubmit={(d) => addMutation.mutate(d)} loading={addMutation.isPending} onCancel={() => setAddOpen(false)} />
      </Modal>

      <Modal open={!!editRole} onClose={() => setEditRole(null)} title="Edit role">
        {editRole && (
          <RoleForm
            initial={{ name: editRole.name, description: editRole.description ?? '', skillSlug: editRole.skillSlug ?? '' }}
            onSubmit={(d) => updateMutation.mutate({ rid: editRole.id, d })}
            loading={updateMutation.isPending}
            onCancel={() => setEditRole(null)}
          />
        )}
      </Modal>

      <ConfirmDialog
        open={!!deleteRole}
        onClose={() => setDeleteRole(null)}
        onConfirm={() => deleteRole && deleteMutation.mutate(deleteRole.id)}
        message={`Delete role "${deleteRole?.name}"?`}
        loading={deleteMutation.isPending}
      />
    </>
  )
}

// ─── Page router ──────────────────────────────────────────────────────────────

export default function TeamsPage() {
  return (
    <Routes>
      <Route index element={<TeamsList />} />
      <Route path=":id" element={<TeamDetail />} />
    </Routes>
  )
}
