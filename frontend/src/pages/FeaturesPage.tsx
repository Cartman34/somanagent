/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Layers, Pencil, Trash2 } from 'lucide-react'
import { featuresApi } from '@/api/features'
import { projectsApi } from '@/api/projects'
import { useTranslation } from '@/hooks/useTranslation'
import { useToast } from '@/hooks/useToast'
import type { FeaturePayload } from '@/api/features'
import type { Feature } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'

const FEATURES_PAGE_TRANSLATION_KEYS = [
  'feature.page.title',
  'feature.page.description',
  'feature.list.loading',
  'common.action.refresh',
  'feature.action.new',
  'feature.select_project',
  'feature.select_prompt',
  'feature.empty.title',
  'feature.empty.description',
  'feature.form.name_label',
  'feature.form.name_placeholder',
  'feature.form.description_label',
  'feature.form.status_label',
  'feature.status.open',
  'feature.status.in_progress',
  'feature.status.closed',
  'common.action.cancel',
  'feature.action.save',
  'feature.action.saving',
  'feature.modal.create',
  'feature.modal.edit',
  'feature.confirm.delete',
  'feature.action.delete',
  'toast.created',
  'toast.saved',
  'toast.deleted',
] as const

const STATUS_BADGE: Record<string, string>  = { open: 'badge-blue', in_progress: 'badge-orange', closed: 'badge-green' }

function FeatureForm({ initial, onSubmit, loading, onCancel, t }: {
  initial?: Partial<FeaturePayload & { id: string }>
  onSubmit: (d: FeaturePayload) => void
  loading: boolean
  onCancel: () => void
  t: (key: string) => string
}) {
  const [name, setName]               = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [status, setStatus]           = useState<FeaturePayload['status']>(initial?.status ?? 'open')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ name, description: description || undefined, status }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">{t('feature.form.name_label')} *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder={t('feature.form.name_placeholder')} />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">{t('feature.form.description_label')}</label>
        <textarea className="input resize-none" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">{t('feature.form.status_label')}</label>
        <select className="input" value={status} onChange={(e) => setStatus(e.target.value as FeaturePayload['status'])}>
          <option value="open">{t('feature.status.open')}</option>
          <option value="in_progress">{t('feature.status.in_progress')}</option>
          <option value="closed">{t('feature.status.closed')}</option>
        </select>
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">{t('common.action.cancel')}</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? t('feature.action.saving') : t('feature.action.save')}</button>
      </div>
    </form>
  )
}

/**
 * Features management page — list, create, edit, and delete features per project.
 */
export default function FeaturesPage() {
  const qc = useQueryClient()
  const [projectId, setProjectId]         = useState('')
  const [createOpen, setCreateOpen]       = useState(false)
  const [editFeature, setEditFeature]     = useState<Feature | null>(null)
  const [deleteFeature, setDeleteFeature] = useState<Feature | null>(null)

  const { data: projects } = useQuery({ queryKey: ['projects'], queryFn: projectsApi.list })
  const { data: features, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['features', projectId],
    queryFn: () => featuresApi.listByProject(projectId),
    enabled: !!projectId,
  })

  const { t } = useTranslation(FEATURES_PAGE_TRANSLATION_KEYS)
  const { toast } = useToast()

  const statusLabels: Record<string, string> = { open: t('feature.status.open'), in_progress: t('feature.status.in_progress'), closed: t('feature.status.closed') }

  const createMutation = useMutation({ mutationFn: (d: FeaturePayload) => featuresApi.create(projectId, d), onSuccess: () => { qc.invalidateQueries({ queryKey: ['features', projectId] }); setCreateOpen(false); toast.success(t('toast.created'), 'feature-create') } })
  const updateMutation = useMutation({ mutationFn: ({ id, data }: { id: string; data: FeaturePayload }) => featuresApi.update(id, data), onSuccess: () => { qc.invalidateQueries({ queryKey: ['features', projectId] }); setEditFeature(null); toast.success(t('toast.saved'), 'feature-update') } })
  const deleteMutation = useMutation({ mutationFn: (id: string) => featuresApi.delete(id), onSuccess: () => { qc.invalidateQueries({ queryKey: ['features', projectId] }); setDeleteFeature(null); toast.success(t('toast.deleted'), 'feature-delete') } })

  return (
    <>
      <PageHeader title={t('feature.page.title')} description={t('feature.page.description')}
        onRefresh={() => { qc.invalidateQueries({ queryKey: ['projects'] }); if( projectId ) qc.invalidateQueries({ queryKey: ['features', projectId] }) }}
        refreshTitle={t('common.action.refresh')}
        action={projectId ? <button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> {t('feature.action.new')}</button> : undefined} />

      <div className="mb-6">
        <label className="block text-sm font-medium text-gray-700 mb-1">{t('feature.select_project')}</label>
        <select className="input max-w-sm" value={projectId} onChange={(e) => setProjectId(e.target.value)}>
          <option value="">— {t('feature.select_prompt')} —</option>
          {projects?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
      </div>

      {!projectId ? null : isLoading ? <PageSpinner /> : error ? (
        <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />
      ) : features?.length === 0 ? (
        <EmptyState icon={Layers} title={t('feature.empty.title')} description={t('feature.empty.description')}
          action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> {t('feature.action.new')}</button>} />
      ) : (
        <div className="relative">
          <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={t('feature.list.loading')} />
          <div className="list-feature grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {features?.map((feature) => (
            <div key={feature.id} className="item-feature card p-4 flex flex-col gap-2">
              <div className="flex items-start justify-between gap-2">
                <p className="font-semibold text-gray-900 text-sm">{feature.name}</p>
                <div className="flex gap-1 flex-shrink-0">
                  <button onClick={() => setEditFeature(feature)} className="p-1 text-gray-400 hover:text-gray-600"><Pencil className="w-3.5 h-3.5" /></button>
                  <button onClick={() => setDeleteFeature(feature)} className="p-1 text-gray-400 hover:text-red-500"><Trash2 className="w-3.5 h-3.5" /></button>
                </div>
              </div>
              {feature.description && <p className="text-xs text-gray-500 line-clamp-2">{feature.description}</p>}
              <div className="mt-auto">
                <span className={`${STATUS_BADGE[feature.status] ?? 'badge-blue'} text-xs`}>{statusLabels[feature.status]}</span>
              </div>
            </div>
          ))}
        </div>
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title={t('feature.modal.create')}>
        <FeatureForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} t={t} />
      </Modal>

      <Modal open={!!editFeature} onClose={() => setEditFeature(null)} title={t('feature.modal.edit')}>
        {editFeature && (
          <FeatureForm initial={{ name: editFeature.name, description: editFeature.description ?? '', status: editFeature.status }}
            onSubmit={(d) => updateMutation.mutate({ id: editFeature.id, data: d })}
            loading={updateMutation.isPending} onCancel={() => setEditFeature(null)} t={t} />
        )}
      </Modal>

      <ConfirmDialog open={!!deleteFeature} onClose={() => setDeleteFeature(null)}
        onConfirm={() => deleteFeature && deleteMutation.mutate(deleteFeature.id)}
        message={t('feature.confirm.delete', { name: deleteFeature?.name ?? '' })}
        loading={deleteMutation.isPending} />
    </>
  )
}
