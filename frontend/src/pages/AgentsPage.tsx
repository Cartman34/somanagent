import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Bot, Pencil, Trash2, CheckCircle, XCircle } from 'lucide-react'
import { agentsApi } from '@/api/agents'
import { rolesApi } from '@/api/roles'
import type { AgentPayload } from '@/api/agents'
import type { Agent, AgentConfig } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'

const defaultConfig: AgentConfig = {
  model: 'claude-sonnet-4-6',
  max_tokens: 4096,
  temperature: 0.7,
  timeout: 120,
}

function AgentForm({ initial, onSubmit, loading, onCancel }: {
  initial?: Partial<Agent>
  onSubmit: (d: AgentPayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const [name, setName]               = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [connector, setConnector]     = useState<'claude_api' | 'claude_cli'>(initial?.connector ?? 'claude_api')
  const [roleId, setRoleId]           = useState(initial?.role?.id ?? '')
  const [isActive, setIsActive]       = useState(initial?.isActive ?? true)
  const [model, setModel]             = useState(initial?.config?.model ?? defaultConfig.model)
  const [maxTokens, setMaxTokens]     = useState(initial?.config?.max_tokens ?? defaultConfig.max_tokens)
  const [temperature, setTemperature] = useState(initial?.config?.temperature ?? defaultConfig.temperature)
  const [timeoutSecs, setTimeoutSecs] = useState(initial?.config?.timeout ?? defaultConfig.timeout)

  const { data: roles } = useQuery({ queryKey: ['roles'], queryFn: rolesApi.list })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    onSubmit({ name, description: description || undefined, connector, isActive, roleId: roleId || undefined, config: { model, max_tokens: maxTokens, temperature, timeout: timeoutSecs } })
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-5">
      <div className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
          <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder="Agent Dev PHP" />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
          <textarea className="input resize-none" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Rôle</label>
          <select className="input" value={roleId} onChange={(e) => setRoleId(e.target.value)}>
            <option value="">— Aucun rôle —</option>
            {roles?.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </select>
        </div>
      </div>

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-2">Connecteur</label>
        <div className="grid grid-cols-2 gap-3">
          {(['claude_api', 'claude_cli'] as const).map((c) => (
            <button key={c} type="button" onClick={() => setConnector(c)}
              className={`p-3 rounded-lg border-2 text-sm font-medium text-left transition-colors ${connector === c ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-gray-300'}`}>
              <p className="font-semibold">{c === 'claude_api' ? 'Claude API' : 'Claude CLI'}</p>
              <p className="text-xs opacity-70 mt-0.5">{c === 'claude_api' ? 'Via HTTPS — clé API requise' : 'Via binaire local'}</p>
            </button>
          ))}
        </div>
      </div>

      <div>
        <p className="text-sm font-medium text-gray-700 mb-3">Configuration</p>
        <div className="space-y-3">
          <div>
            <label className="block text-xs text-gray-500 mb-1">Modèle</label>
            <input className="input" value={model} onChange={(e) => setModel(e.target.value)} placeholder="claude-sonnet-4-6" />
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="block text-xs text-gray-500 mb-1">Max tokens</label>
              <input className="input" type="number" min={1} max={200000} value={maxTokens} onChange={(e) => setMaxTokens(Number(e.target.value))} />
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">Température</label>
              <input className="input" type="number" min={0} max={1} step={0.1} value={temperature} onChange={(e) => setTemperature(Number(e.target.value))} />
            </div>
            <div>
              <label className="block text-xs text-gray-500 mb-1">Timeout (s)</label>
              <input className="input" type="number" min={5} max={600} value={timeoutSecs} onChange={(e) => setTimeoutSecs(Number(e.target.value))} />
            </div>
          </div>
        </div>
      </div>

      <label className="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} className="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
        <span className="text-sm text-gray-700">Agent actif</span>
      </label>

      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Annuler</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? 'Enregistrement…' : 'Enregistrer'}</button>
      </div>
    </form>
  )
}

export default function AgentsPage() {
  const qc = useQueryClient()
  const [createOpen, setCreateOpen]   = useState(false)
  const [editAgent, setEditAgent]     = useState<Agent | null>(null)
  const [deleteAgent, setDeleteAgent] = useState<Agent | null>(null)

  const { data: agents, isLoading, error, refetch } = useQuery({ queryKey: ['agents'], queryFn: agentsApi.list })

  const createMutation = useMutation({ mutationFn: agentsApi.create, onSuccess: () => { qc.invalidateQueries({ queryKey: ['agents'] }); setCreateOpen(false) } })
  const updateMutation = useMutation({ mutationFn: ({ id, data }: { id: string; data: AgentPayload }) => agentsApi.update(id, data), onSuccess: () => { qc.invalidateQueries({ queryKey: ['agents'] }); setEditAgent(null) } })
  const deleteMutation = useMutation({ mutationFn: (id: string) => agentsApi.delete(id), onSuccess: () => { qc.invalidateQueries({ queryKey: ['agents'] }); setDeleteAgent(null) } })

  if (isLoading) return <PageSpinner />
  if (error) return <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />

  return (
    <>
      <PageHeader title="Agents" description="Configurez les agents IA et assignez-leur un rôle."
        action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> Nouvel agent</button>} />

      {agents?.length === 0 ? (
        <EmptyState icon={Bot} title="Aucun agent" description="Créez votre premier agent et configurez son connecteur IA."
          action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> Nouvel agent</button>} />
      ) : (
        <div className="card overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Nom</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600 hidden sm:table-cell">Rôle</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Connecteur</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600 hidden md:table-cell">Modèle</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Statut</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {agents?.map((agent) => (
                <tr key={agent.id} className="hover:bg-gray-50 transition-colors">
                  <td className="px-4 py-3">
                    <p className="font-medium text-gray-900">{agent.name}</p>
                    {agent.description && <p className="text-xs text-gray-400 truncate max-w-xs">{agent.description}</p>}
                  </td>
                  <td className="px-4 py-3 hidden sm:table-cell text-gray-600">
                    {agent.role?.name ?? <span className="text-gray-400">—</span>}
                  </td>
                  <td className="px-4 py-3">
                    <span className={agent.connector === 'claude_api' ? 'badge-blue' : 'badge-orange'}>{agent.connectorLabel}</span>
                  </td>
                  <td className="px-4 py-3 hidden md:table-cell text-gray-500 font-mono text-xs">{agent.config.model}</td>
                  <td className="px-4 py-3">
                    {agent.isActive ? <CheckCircle className="w-4 h-4 text-green-500" /> : <XCircle className="w-4 h-4 text-gray-300" />}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex gap-1 justify-end">
                      <button onClick={() => setEditAgent(agent)} className="p-1.5 text-gray-400 hover:text-gray-600" title="Modifier"><Pencil className="w-4 h-4" /></button>
                      <button onClick={() => setDeleteAgent(agent)} className="p-1.5 text-gray-400 hover:text-red-500" title="Supprimer"><Trash2 className="w-4 h-4" /></button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Nouvel agent" size="lg">
        <AgentForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} />
      </Modal>

      <Modal open={!!editAgent} onClose={() => setEditAgent(null)} title="Modifier l'agent" size="lg">
        {editAgent && (
          <AgentForm initial={editAgent} onSubmit={(d) => updateMutation.mutate({ id: editAgent.id, data: d })} loading={updateMutation.isPending} onCancel={() => setEditAgent(null)} />
        )}
      </Modal>

      <ConfirmDialog open={!!deleteAgent} onClose={() => setDeleteAgent(null)}
        onConfirm={() => deleteAgent && deleteMutation.mutate(deleteAgent.id)}
        message={`Supprimer l'agent "${deleteAgent?.name}" ? Cette action est irréversible.`}
        loading={deleteMutation.isPending} />
    </>
  )
}
