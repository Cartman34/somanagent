import { useState } from 'react'
import { Routes, Route, useNavigate, useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Users, ArrowLeft, Pencil, Trash2, Bot, UserMinus } from 'lucide-react'
import { teamsApi } from '@/api/teams'
import { agentsApi } from '@/api/agents'
import type { TeamPayload } from '@/api/teams'
import type { AgentSummary } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'

function fmt(date: string) {
  return new Date(date).toLocaleDateString('fr-FR', { year: 'numeric', month: 'short', day: 'numeric' })
}

// ─── Formulaire équipe ────────────────────────────────────────────────────────

function TeamForm({ initial, onSubmit, loading, onCancel }: {
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
        <label className="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder="Équipe Web" />
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

// ─── Liste des équipes ────────────────────────────────────────────────────────

function TeamsList() {
  const navigate = useNavigate()
  const qc = useQueryClient()

  const [createOpen, setCreateOpen] = useState(false)
  const [editTarget, setEditTarget] = useState<{ id: string; name: string; description: string | null } | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<{ id: string; name: string } | null>(null)

  const { data: teams, isLoading, error, refetch } = useQuery({ queryKey: ['teams'], queryFn: teamsApi.list })

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
        title="Équipes"
        description="Regroupez vos agents en équipes spécialisées."
        action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> Nouvelle équipe</button>}
      />

      {teams?.length === 0 ? (
        <EmptyState icon={Users} title="Aucune équipe" description="Créez une équipe et ajoutez-y des agents."
          action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> Nouvelle équipe</button>} />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {teams?.map((team) => (
            <div key={team.id} className="card p-5 flex flex-col gap-3 hover:shadow-md transition-shadow">
              <div className="flex items-start justify-between gap-2">
                <button onClick={() => navigate(`/teams/${team.id}`)} className="text-left font-semibold text-gray-900 hover:text-brand-600 transition-colors">
                  {team.name}
                </button>
                <div className="flex gap-1 flex-shrink-0">
                  <button onClick={() => setEditTarget(team)} className="p-1.5 text-gray-400 hover:text-gray-600" title="Modifier"><Pencil className="w-4 h-4" /></button>
                  <button onClick={() => setDeleteTarget(team)} className="p-1.5 text-gray-400 hover:text-red-500" title="Supprimer"><Trash2 className="w-4 h-4" /></button>
                </div>
              </div>
              {team.description && <p className="text-sm text-gray-500 line-clamp-2">{team.description}</p>}
              <div className="mt-auto flex items-center justify-between text-xs text-gray-400">
                <span>{team.agentCount} agent{team.agentCount !== 1 ? 's' : ''}</span>
                <span>{fmt(team.createdAt)}</span>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Nouvelle équipe">
        <TeamForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} />
      </Modal>

      <Modal open={!!editTarget} onClose={() => setEditTarget(null)} title="Modifier l'équipe">
        {editTarget && (
          <TeamForm initial={{ name: editTarget.name, description: editTarget.description ?? '' }}
            onSubmit={(d) => updateMutation.mutate({ id: editTarget.id, data: d })}
            loading={updateMutation.isPending} onCancel={() => setEditTarget(null)} />
        )}
      </Modal>

      <ConfirmDialog open={!!deleteTarget} onClose={() => setDeleteTarget(null)}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
        message={`Supprimer l'équipe "${deleteTarget?.name}" ? Cette action est irréversible.`}
        loading={deleteMutation.isPending} />
    </>
  )
}

// ─── Détail équipe ────────────────────────────────────────────────────────────

function TeamDetail() {
  const { id } = useParams<{ id: string }>()
  const qc = useQueryClient()
  const [addOpen, setAddOpen] = useState(false)
  const [selectedAgentId, setSelectedAgentId] = useState('')
  const [removeTarget, setRemoveTarget] = useState<AgentSummary | null>(null)

  const { data: team, isLoading, error, refetch } = useQuery({ queryKey: ['teams', id], queryFn: () => teamsApi.get(id!), enabled: !!id })
  const { data: allAgents } = useQuery({ queryKey: ['agents'], queryFn: agentsApi.list })

  const addMutation = useMutation({
    mutationFn: (agentId: string) => teamsApi.addAgent(id!, agentId),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['teams', id] }); setAddOpen(false); setSelectedAgentId('') },
  })
  const removeMutation = useMutation({
    mutationFn: (agentId: string) => teamsApi.removeAgent(id!, agentId),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['teams', id] }); setRemoveTarget(null) },
  })

  if (isLoading) return <PageSpinner />
  if (error || !team) return <ErrorMessage message={(error as Error)?.message ?? 'Équipe introuvable'} onRetry={() => refetch()} />

  const agents = team.agents ?? []
  const memberIds = new Set(agents.map((a) => a.id))
  const availableAgents = allAgents?.filter((a) => !memberIds.has(a.id)) ?? []

  return (
    <>
      <Link to="/teams" className="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 mb-4">
        <ArrowLeft className="w-4 h-4" /> Équipes
      </Link>

      <PageHeader title={team.name} description={team.description ?? undefined}
        action={<button className="btn-primary" onClick={() => setAddOpen(true)}><Plus className="w-4 h-4" /> Ajouter un agent</button>} />

      <h2 className="text-base font-semibold text-gray-900 mb-3">Membres ({agents.length})</h2>

      {agents.length === 0 ? (
        <EmptyState icon={Bot} title="Aucun agent" description="Ajoutez des agents à cette équipe."
          action={<button className="btn-primary" onClick={() => setAddOpen(true)}><Plus className="w-4 h-4" /> Ajouter un agent</button>} />
      ) : (
        <div className="card divide-y divide-gray-100">
          {agents.map((agent) => (
            <div key={agent.id} className="flex items-center gap-3 px-4 py-3">
              <Bot className="w-4 h-4 text-gray-400 flex-shrink-0" />
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-gray-900">{agent.name}</p>
                {agent.role && <p className="text-xs text-gray-500">{agent.role.name}</p>}
              </div>
              {!agent.isActive && <span className="badge-orange text-xs">Inactif</span>}
              <button onClick={() => setRemoveTarget(agent)} className="p-1.5 text-gray-400 hover:text-red-500" title="Retirer">
                <UserMinus className="w-3.5 h-3.5" />
              </button>
            </div>
          ))}
        </div>
      )}

      <Modal open={addOpen} onClose={() => setAddOpen(false)} title="Ajouter un agent">
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Agent</label>
            <select className="input" value={selectedAgentId} onChange={(e) => setSelectedAgentId(e.target.value)}>
              <option value="">— Sélectionner —</option>
              {availableAgents.map((a) => (
                <option key={a.id} value={a.id}>{a.name}{a.role ? ` (${a.role.name})` : ''}</option>
              ))}
            </select>
          </div>
          <div className="flex justify-end gap-3 pt-2">
            <button className="btn-secondary" onClick={() => setAddOpen(false)}>Annuler</button>
            <button className="btn-primary" disabled={!selectedAgentId || addMutation.isPending}
              onClick={() => selectedAgentId && addMutation.mutate(selectedAgentId)}>
              {addMutation.isPending ? 'Ajout…' : 'Ajouter'}
            </button>
          </div>
        </div>
      </Modal>

      <ConfirmDialog open={!!removeTarget} onClose={() => setRemoveTarget(null)}
        onConfirm={() => removeTarget && removeMutation.mutate(removeTarget.id)}
        message={`Retirer "${removeTarget?.name}" de l'équipe ?`}
        loading={removeMutation.isPending} />
    </>
  )
}

export default function TeamsPage() {
  return (
    <Routes>
      <Route index element={<TeamsList />} />
      <Route path=":id" element={<TeamDetail />} />
    </Routes>
  )
}
