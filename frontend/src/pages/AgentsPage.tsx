/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Bot, Pencil, Trash2, CheckCircle, XCircle } from 'lucide-react'
import { agentsApi } from '@/api/agents'
import { useTranslation } from '@/hooks/useTranslation'
import { rolesApi } from '@/api/roles'
import { healthApi } from '@/api/health'
import type { AgentPayload } from '@/api/agents'
import type { Agent, AgentConfig } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'

const AGENT_FORM_TRANSLATION_KEYS = [
  'agent.form.label.name',
  'agent.form.placeholder.name',
  'agent.form.label.description',
  'agent.form.label.role',
  'agent.form.label.no_role',
  'agent.form.label.connector',
  'agent.form.label.claude_api',
  'agent.form.label.claude_cli',
  'agent.form.desc.claude_api',
  'agent.form.desc.claude_cli',
  'agent.form.label.configuration',
  'agent.form.label.model',
  'agent.form.placeholder.model',
  'agent.form.label.max_tokens',
  'agent.form.label.temperature',
  'agent.form.label.timeout',
  'agent.form.label.is_active',
  'agent.form.action.cancel',
  'agent.form.action.save',
  'agent.form.action.saving',
] as const

const AGENTS_PAGE_TRANSLATION_KEYS = [
  ...AGENT_FORM_TRANSLATION_KEYS,
  'agent.page.title',
  'agent.page.description',
  'agent.action.new',
  'agent.empty.title',
  'agent.empty.description',
  'agent.status.claude_cli',
  'agent.status.connected',
  'agent.status.not_connected',
  'agent.status.login_manual',
  'agent.status.verify',
  'agent.action.edit',
  'agent.action.delete',
  'agent.action.delete_confirm',
  'agent.edit.title',
  'agent.create.title',
  'agent.list.loading',
  'common.action.refresh',
] as const

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
  const { t } = useTranslation(AGENT_FORM_TRANSLATION_KEYS)
  const [name, setName]               = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [connector, setConnector]     = useState<'claude_api' | 'claude_cli'>(initial?.connector ?? 'claude_api')
  const [roleId, setRoleId]           = useState(initial?.role?.id ?? '')
  const [isActive, setIsActive]       = useState(initial?.isActive ?? true)
  const [model, setModel]             = useState(initial?.config?.model ?? defaultConfig.model)
  const [maxTokens, setMaxTokens]     = useState(initial?.config?.max_tokens ?? defaultConfig.max_tokens)
  const [temperature, setTemperature] = useState(initial?.config?.temperature ?? defaultConfig.temperature)
  const [timeoutSecs, setTimeoutSecs] = useState(initial?.config?.timeout ?? defaultConfig.timeout)

  const { data: roles } = useQuery<Awaited<ReturnType<typeof rolesApi.list>>>({ queryKey: ['roles'], queryFn: rolesApi.list })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    onSubmit({ name, description: description || undefined, connector, isActive, roleId: roleId || undefined, config: { model, max_tokens: maxTokens, temperature, timeout: timeoutSecs } })
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-5">
      <div className="space-y-4">
        <div>
          <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{t('agent.form.label.name')} *</label>
          <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder={t('agent.form.placeholder.name')} />
        </div>
        <div>
          <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{t('agent.form.label.description')}</label>
          <textarea className="input resize-none" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>
        <div>
          <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{t('agent.form.label.role')}</label>
          <select className="input" value={roleId} onChange={(e) => setRoleId(e.target.value)}>
            <option value="">{t('agent.form.label.no_role')}</option>
            {roles?.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </select>
        </div>
      </div>

      <div>
        <label className="block text-sm font-medium mb-2" style={{ color: 'var(--text)' }}>{t('agent.form.label.connector')}</label>
        <div className="grid grid-cols-2 gap-3">
          {(['claude_api', 'claude_cli'] as const).map((c) => (
            <button
              key={c}
              type="button"
              onClick={() => setConnector(c)}
              className="rounded-lg border-2 p-3 text-left text-sm font-medium transition-colors"
              style={{
                borderColor: connector === c ? 'var(--brand)' : 'var(--border)',
                background: connector === c ? 'var(--brand-dim)' : 'var(--surface)',
                color: connector === c ? 'var(--text)' : 'var(--muted)',
              }}
            >
              <p className="font-semibold" style={{ color: 'var(--text)' }}>{c === 'claude_api' ? t('agent.form.label.claude_api') : t('agent.form.label.claude_cli')}</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--muted)' }}>{c === 'claude_api' ? t('agent.form.desc.claude_api') : t('agent.form.desc.claude_cli')}</p>
            </button>
          ))}
        </div>
      </div>

      <div>
        <p className="text-sm font-medium mb-3" style={{ color: 'var(--text)' }}>{t('agent.form.label.configuration')}</p>
        <div className="space-y-3">
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--muted)' }}>{t('agent.form.label.model')}</label>
            <input className="input" value={model} onChange={(e) => setModel(e.target.value)} placeholder={t('agent.form.placeholder.model')} />
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="block text-xs mb-1" style={{ color: 'var(--muted)' }}>{t('agent.form.label.max_tokens')}</label>
              <input className="input" type="number" min={1} max={200000} value={maxTokens} onChange={(e) => setMaxTokens(Number(e.target.value))} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={{ color: 'var(--muted)' }}>{t('agent.form.label.temperature')}</label>
              <input className="input" type="number" min={0} max={1} step={0.1} value={temperature} onChange={(e) => setTemperature(Number(e.target.value))} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={{ color: 'var(--muted)' }}>{t('agent.form.label.timeout')}</label>
              <input className="input" type="number" min={5} max={600} value={timeoutSecs} onChange={(e) => setTimeoutSecs(Number(e.target.value))} />
            </div>
          </div>
        </div>
      </div>

      <label className="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} className="rounded" style={{ accentColor: 'var(--brand)' }} />
        <span className="text-sm" style={{ color: 'var(--text)' }}>{t('agent.form.label.is_active')}</span>
      </label>

      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">{t('agent.form.action.cancel')}</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? t('agent.form.action.saving') : t('agent.form.action.save')}</button>
      </div>
    </form>
  )
}

/**
 * Agents management page — list, create, edit, and delete agents.
 */
export default function AgentsPage() {
  const qc = useQueryClient()
  const { t } = useTranslation(AGENTS_PAGE_TRANSLATION_KEYS)
  const [createOpen, setCreateOpen]   = useState(false)
  const [editAgent, setEditAgent]     = useState<Agent | null>(null)
  const [deleteAgent, setDeleteAgent] = useState<Agent | null>(null)

  const { data: agents, isLoading, isFetching, error, refetch } = useQuery<Awaited<ReturnType<typeof agentsApi.list>>>({ queryKey: ['agents'], queryFn: agentsApi.list })
  const { data: claudeCliAuth } = useQuery({ queryKey: ['health', 'claude-cli-auth'], queryFn: healthApi.claudeCliAuth, retry: false })

  const createMutation = useMutation({ mutationFn: agentsApi.create, onSuccess: () => { qc.invalidateQueries({ queryKey: ['agents'] }); setCreateOpen(false) } })
  const updateMutation = useMutation({ mutationFn: ({ id, data }: { id: string; data: AgentPayload }) => agentsApi.update(id, data), onSuccess: () => { qc.invalidateQueries({ queryKey: ['agents'] }); setEditAgent(null) } })
  const deleteMutation = useMutation({ mutationFn: (id: string) => agentsApi.delete(id), onSuccess: () => { qc.invalidateQueries({ queryKey: ['agents'] }); setDeleteAgent(null) } })

  if (isLoading) return <PageSpinner />
  if (error) return <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />

  return (
    <>
      <PageHeader title={t('agent.page.title')} description={t('agent.page.description')}
        onRefresh={() => qc.invalidateQueries({ queryKey: ['agents'] })}
        refreshTitle={t('common.action.refresh')}
        action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> {t('agent.action.new')}</button>} />

      <div className="card p-4 mb-4">
        <div className="flex items-start gap-3">
          {claudeCliAuth?.loggedIn ? (
            <CheckCircle className="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" />
          ) : (
            <XCircle className="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" />
          )}
          <div className="min-w-0">
            <p className="text-sm font-medium" style={{ color: 'var(--text)' }}>{t('agent.status.claude_cli')}</p>
            <p className="text-xs mt-0.5" style={{ color: 'var(--muted)' }}>
              {claudeCliAuth?.loggedIn
                ? t('agent.status.connected')
                : t('agent.status.not_connected')}
            </p>
            {!claudeCliAuth?.loggedIn && (
              <div className="mt-2 text-xs space-y-1" style={{ color: 'var(--muted)' }}>
                <p>{t('agent.status.login_manual')} <code>php scripts/claude-auth.php login</code></p>
                <p>{t('agent.status.verify')} <code>php scripts/claude-auth.php status</code></p>
              </div>
            )}
          </div>
        </div>
      </div>

      {agents?.length === 0 ? (
        <EmptyState icon={Bot} title={t('agent.empty.title')} description={t('agent.empty.description')}
          action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> {t('agent.action.new')}</button>} />
      ) : (
        <div className="relative">
          <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={t('agent.list.loading')} />
          <div className="list-agent card overflow-hidden">
            <table className="w-full text-sm">
            <thead className="border-b" style={{ background: 'var(--surface2)', borderColor: 'var(--border)' }}>
              <tr>
                <th className="text-left px-4 py-3 font-medium" style={{ color: 'var(--muted)' }}>{t('agent.form.label.name')}</th>
                <th className="hidden px-4 py-3 text-left font-medium sm:table-cell" style={{ color: 'var(--muted)' }}>{t('agent.form.label.role')}</th>
                <th className="text-left px-4 py-3 font-medium" style={{ color: 'var(--muted)' }}>{t('agent.form.label.connector')}</th>
                <th className="hidden px-4 py-3 text-left font-medium md:table-cell" style={{ color: 'var(--muted)' }}>{t('agent.form.label.model')}</th>
                <th className="text-left px-4 py-3 font-medium" style={{ color: 'var(--muted)' }}>{t('agent.table.header.status')}</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
              <tbody className="divide-y" style={{ borderColor: 'var(--border)' }}>
              {agents?.map((agent) => (
                <tr key={agent.id} className="item-agent transition-colors" style={{ background: 'transparent' }}>
                  <td className="px-4 py-3">
                    <p className="font-medium" style={{ color: 'var(--text)' }}>{agent.name}</p>
                    {agent.description && <p className="text-xs truncate max-w-xs" style={{ color: 'var(--muted)' }}>{agent.description}</p>}
                  </td>
                  <td className="hidden px-4 py-3 sm:table-cell" style={{ color: 'var(--muted)' }}>
                    {agent.role?.name ?? <span style={{ color: 'var(--muted)' }}>—</span>}
                  </td>
                  <td className="px-4 py-3">
                    <span className={agent.connector === 'claude_api' ? 'badge-blue' : 'badge-orange'}>{agent.connectorLabel}</span>
                  </td>
                  <td className="hidden px-4 py-3 font-mono text-xs md:table-cell" style={{ color: 'var(--muted)' }}>{agent.config.model}</td>
                  <td className="px-4 py-3">
                    {agent.isActive ? <CheckCircle className="w-4 h-4 text-green-500" /> : <XCircle className="w-4 h-4 text-gray-300" />}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex gap-1 justify-end">
                      <button onClick={() => setEditAgent(agent)} className="p-1.5 transition-colors" style={{ color: 'var(--muted)' }} title={t('agent.action.edit')}><Pencil className="w-4 h-4" /></button>
                      <button onClick={() => setDeleteAgent(agent)} className="p-1.5 transition-colors" style={{ color: 'var(--muted)' }} title={t('agent.action.delete')}><Trash2 className="w-4 h-4" /></button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
            </table>
          </div>
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title={t('agent.create.title')} size="lg">
        <AgentForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} />
      </Modal>

      <Modal open={!!editAgent} onClose={() => setEditAgent(null)} title={t('agent.edit.title')} size="lg">
        {editAgent && (
          <AgentForm initial={editAgent} onSubmit={(d) => updateMutation.mutate({ id: editAgent.id, data: d })} loading={updateMutation.isPending} onCancel={() => setEditAgent(null)} />
        )}
      </Modal>

      <ConfirmDialog open={!!deleteAgent} onClose={() => setDeleteAgent(null)}
        onConfirm={() => deleteAgent && deleteMutation.mutate(deleteAgent.id)}
        message={t('agent.action.delete_confirm', { name: deleteAgent?.name ?? '' })}
        loading={deleteMutation.isPending} />
    </>
  )
}
