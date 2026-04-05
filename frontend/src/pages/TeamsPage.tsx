/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState } from 'react'
import { Routes, Route, useNavigate, useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Users, ArrowLeft, Pencil, Trash2, Bot, UserMinus } from 'lucide-react'
import { teamsApi } from '@/api/teams'
import { agentsApi } from '@/api/agents'
import type { TeamPayload } from '@/api/teams'
import type { AgentSummary } from '@/types'
import { useTranslation } from '@/hooks/useTranslation'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'

const TEAMS_PAGE_TRANSLATION_KEYS = [
  'common.action.add',
  'common.action.adding',
  'common.action.cancel',
  'common.action.delete',
  'common.action.edit',
  'common.action.refresh',
  'common.action.save',
  'common.action.saving',
  'team.item.loading',
  'team.list.loading',
  'team.action.add_agent',
  'team.action.new_team',
  'team.action.remove',
  'team.confirm.delete.message',
  'team.confirm.remove.message',
  'team.detail.empty.description',
  'team.detail.empty.title',
  'team.detail.inactive',
  'team.detail.members_title',
  'team.error.not_found',
  'team.form.description_label',
  'team.form.name_label',
  'team.form.name_placeholder',
  'team.list.agent_count',
  'team.list.empty.description',
  'team.list.empty.title',
  'team.modal.add_agent.label',
  'team.modal.add_agent.select_placeholder',
  'team.modal.add_agent.title',
  'team.modal.create.title',
  'team.modal.edit.title',
  'team.page.description',
  'team.page.title',
] as const

function TeamForm({ initial, onSubmit, loading, onCancel }: {
  initial?: Partial<TeamPayload>
  onSubmit: (d: TeamPayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const { t } = useTranslation(TEAMS_PAGE_TRANSLATION_KEYS)
  const [name, setName] = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ name, description: description || undefined }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">{t('team.form.name_label')} *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder={t('team.form.name_placeholder')} />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">{t('team.form.description_label')}</label>
        <textarea className="input resize-none" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">{t('common.action.cancel')}</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? t('common.action.saving') : t('common.action.save')}</button>
      </div>
    </form>
  )
}

function TeamsList() {
  const { t, formatDate } = useTranslation(TEAMS_PAGE_TRANSLATION_KEYS)
  const navigate = useNavigate()
  const qc = useQueryClient()

  const [createOpen, setCreateOpen] = useState(false)
  const [editTarget, setEditTarget] = useState<{ id: string; name: string; description: string | null } | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<{ id: string; name: string } | null>(null)

  const { data: teams, isLoading, isFetching, error, refetch } = useQuery({ queryKey: ['teams'], queryFn: teamsApi.list })

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
        title={t('team.page.title')}
        description={t('team.page.description')}
        onRefresh={() => qc.invalidateQueries({ queryKey: ['teams'] })}
        refreshTitle={t('common.action.refresh')}
        action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> {t('team.action.new_team')}</button>}
      />

      <div className="relative">
        <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={t('team.list.loading')} />

        {teams?.length === 0 ? (
          <EmptyState icon={Users} title={t('team.list.empty.title')} description={t('team.list.empty.description')}
            action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> {t('team.action.new_team')}</button>} />
        ) : (
          <div className="list-team grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {teams?.map((team) => (
              <div
                key={team.id}
                role="button"
                tabIndex={0}
                onClick={() => navigate(`/teams/${team.id}`)}
                onKeyDown={(e) => e.key === 'Enter' && navigate(`/teams/${team.id}`)}
                className="item-team card p-5 flex flex-col gap-3 hover:shadow-md transition-shadow cursor-pointer"
              >
                <div className="flex items-start justify-between gap-2">
                  <span className="font-semibold text-gray-900 hover:text-brand-600 transition-colors">
                    {team.name}
                  </span>
                  <div className="flex gap-1 flex-shrink-0" onClick={(e) => e.stopPropagation()}>
                    <button onClick={() => setEditTarget(team)} className="p-1.5 text-gray-400 hover:text-gray-600" title={t('common.action.edit')}><Pencil className="w-4 h-4" /></button>
                    <button onClick={() => setDeleteTarget(team)} className="p-1.5 text-gray-400 hover:text-red-500" title={t('common.action.delete')}><Trash2 className="w-4 h-4" /></button>
                  </div>
                </div>
                {team.description && <p className="text-sm text-gray-500 line-clamp-2">{team.description}</p>}
                <div className="mt-auto flex items-center justify-between text-xs text-gray-400">
                  <span>{team.agentCount} {t('team.list.agent_count', { count: team.agentCount !== 1 ? 'agents' : 'agent' })}</span>
                  <span>{formatDate(team.createdAt)}</span>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title={t('team.modal.create.title')}>
        <TeamForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} />
      </Modal>

      <Modal open={!!editTarget} onClose={() => setEditTarget(null)} title={t('team.modal.edit.title')}>
        {editTarget && (
          <TeamForm initial={{ name: editTarget.name, description: editTarget.description ?? '' }}
            onSubmit={(d) => updateMutation.mutate({ id: editTarget.id, data: d })}
            loading={updateMutation.isPending} onCancel={() => setEditTarget(null)} />
        )}
      </Modal>

      <ConfirmDialog open={!!deleteTarget} onClose={() => setDeleteTarget(null)}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
        message={t('team.confirm.delete.message', { name: deleteTarget?.name ?? '' })}
        loading={deleteMutation.isPending} />
    </>
  )
}

function TeamDetail() {
  const { t } = useTranslation(TEAMS_PAGE_TRANSLATION_KEYS)
  const { id } = useParams<{ id: string }>()
  const qc = useQueryClient()
  const [addOpen, setAddOpen] = useState(false)
  const [selectedAgentId, setSelectedAgentId] = useState('')
  const [removeTarget, setRemoveTarget] = useState<AgentSummary | null>(null)

  const { data: team, isLoading, isFetching, error, refetch } = useQuery({ queryKey: ['teams', id], queryFn: () => teamsApi.get(id!), enabled: !!id })
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
  if (error || !team) return <ErrorMessage message={(error as Error)?.message ?? t('team.error.not_found')} onRetry={() => refetch()} />

  const agents = team.agents ?? []
  const memberIds = new Set(agents.map((a) => a.id))
  const availableAgents = allAgents?.filter((a) => !memberIds.has(a.id)) ?? []

  return (
    <>
      <Link to="/teams" className="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 mb-4">
        <ArrowLeft className="w-4 h-4" /> {t('team.page.title')}
      </Link>

      <PageHeader title={team.name} description={team.description ?? undefined}
        onRefresh={() => qc.invalidateQueries({ queryKey: ['teams', id] })}
        refreshTitle={t('common.action.refresh')}
        action={<button className="btn-primary" onClick={() => setAddOpen(true)}><Plus className="w-4 h-4" /> {t('team.action.add_agent')}</button>} />

      <div className="relative">
        <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={t('team.item.loading')} />

        <h2 className="text-base font-semibold text-gray-900 mb-3">{t('team.detail.members_title', { count: String(agents.length) })}</h2>

        {agents.length === 0 ? (
          <EmptyState icon={Bot} title={t('team.detail.empty.title')} description={t('team.detail.empty.description')}
            action={<button className="btn-primary" onClick={() => setAddOpen(true)}><Plus className="w-4 h-4" /> {t('team.action.add_agent')}</button>} />
        ) : (
          <div className="list-agent card divide-y divide-gray-100">
            {agents.map((agent) => (
              <div key={agent.id} className="item-agent flex items-center gap-3 px-4 py-3">
                <Bot className="w-4 h-4 text-gray-400 flex-shrink-0" />
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-gray-900">{agent.name}</p>
                  {agent.role && <p className="text-xs text-gray-500">{agent.role.name}</p>}
                </div>
                {!agent.isActive && <span className="badge-orange text-xs">{t('team.detail.inactive')}</span>}
                <button onClick={() => setRemoveTarget(agent)} className="p-1.5 text-gray-400 hover:text-red-500" title={t('team.action.remove')}>
                  <UserMinus className="w-3.5 h-3.5" />
                </button>
              </div>
            ))}
          </div>
        )}
      </div>

      <Modal open={addOpen} onClose={() => setAddOpen(false)} title={t('team.modal.add_agent.title')}>
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{t('team.modal.add_agent.label')}</label>
            <select className="input" value={selectedAgentId} onChange={(e) => setSelectedAgentId(e.target.value)}>
              <option value="">{t('team.modal.add_agent.select_placeholder')}</option>
              {availableAgents.map((a) => (
                <option key={a.id} value={a.id}>{a.name}{a.role ? ` (${a.role.name})` : ''}</option>
              ))}
            </select>
          </div>
          <div className="flex justify-end gap-3 pt-2">
            <button className="btn-secondary" onClick={() => setAddOpen(false)}>{t('common.action.cancel')}</button>
            <button className="btn-primary" disabled={!selectedAgentId || addMutation.isPending}
              onClick={() => selectedAgentId && addMutation.mutate(selectedAgentId)}>
              {addMutation.isPending ? t('common.action.adding') : t('common.action.add')}
            </button>
          </div>
        </div>
      </Modal>

      <ConfirmDialog open={!!removeTarget} onClose={() => setRemoveTarget(null)}
        onConfirm={() => removeTarget && removeMutation.mutate(removeTarget.id)}
        message={t('team.confirm.remove.message', { name: removeTarget?.name ?? '' })}
        loading={removeMutation.isPending} />
    </>
  )
}

/**
 * Teams management page — routes to teams list and team detail views.
 */
export default function TeamsPage() {
  return (
    <Routes>
      <Route index element={<TeamsList />} />
      <Route path=":id" element={<TeamDetail />} />
    </Routes>
  )
}
