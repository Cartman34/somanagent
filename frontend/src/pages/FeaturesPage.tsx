import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Layers, Pencil, Trash2 } from 'lucide-react'
import { featuresApi } from '@/api/features'
import { projectsApi } from '@/api/projects'
import type { FeaturePayload } from '@/api/features'
import type { Feature } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'

const STATUS_LABELS: Record<string, string> = { open: 'Ouverte', in_progress: 'En cours', closed: 'Fermée' }
const STATUS_BADGE: Record<string, string>  = { open: 'badge-blue', in_progress: 'badge-orange', closed: 'badge-green' }

function FeatureForm({ initial, onSubmit, loading, onCancel }: {
  initial?: Partial<FeaturePayload & { id: string }>
  onSubmit: (d: FeaturePayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const [name, setName]               = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [status, setStatus]           = useState<FeaturePayload['status']>(initial?.status ?? 'open')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ name, description: description || undefined, status }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required placeholder="Authentification utilisateur" />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea className="input resize-none" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Statut</label>
        <select className="input" value={status} onChange={(e) => setStatus(e.target.value as FeaturePayload['status'])}>
          <option value="open">Ouverte</option>
          <option value="in_progress">En cours</option>
          <option value="closed">Fermée</option>
        </select>
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Annuler</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? 'Enregistrement…' : 'Enregistrer'}</button>
      </div>
    </form>
  )
}

export default function FeaturesPage() {
  const qc = useQueryClient()
  const [projectId, setProjectId]         = useState('')
  const [createOpen, setCreateOpen]       = useState(false)
  const [editFeature, setEditFeature]     = useState<Feature | null>(null)
  const [deleteFeature, setDeleteFeature] = useState<Feature | null>(null)

  const { data: projects } = useQuery({ queryKey: ['projects'], queryFn: projectsApi.list })
  const { data: features, isLoading, error, refetch } = useQuery({
    queryKey: ['features', projectId],
    queryFn: () => featuresApi.listByProject(projectId),
    enabled: !!projectId,
  })

  const createMutation = useMutation({ mutationFn: (d: FeaturePayload) => featuresApi.create(projectId, d), onSuccess: () => { qc.invalidateQueries({ queryKey: ['features', projectId] }); setCreateOpen(false) } })
  const updateMutation = useMutation({ mutationFn: ({ id, data }: { id: string; data: FeaturePayload }) => featuresApi.update(id, data), onSuccess: () => { qc.invalidateQueries({ queryKey: ['features', projectId] }); setEditFeature(null) } })
  const deleteMutation = useMutation({ mutationFn: (id: string) => featuresApi.delete(id), onSuccess: () => { qc.invalidateQueries({ queryKey: ['features', projectId] }); setDeleteFeature(null) } })

  return (
    <>
      <PageHeader title="Features" description="Regroupez les tâches sous des fonctionnalités métier."
        action={projectId ? <button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> Nouvelle feature</button> : undefined} />

      <div className="mb-6">
        <label className="block text-sm font-medium text-gray-700 mb-1">Projet</label>
        <select className="input max-w-sm" value={projectId} onChange={(e) => setProjectId(e.target.value)}>
          <option value="">— Sélectionner un projet —</option>
          {projects?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
      </div>

      {!projectId ? null : isLoading ? <PageSpinner /> : error ? (
        <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />
      ) : features?.length === 0 ? (
        <EmptyState icon={Layers} title="Aucune feature" description="Créez des features pour organiser vos tâches."
          action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> Nouvelle feature</button>} />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {features?.map((feature) => (
            <div key={feature.id} className="card p-4 flex flex-col gap-2">
              <div className="flex items-start justify-between gap-2">
                <p className="font-semibold text-gray-900 text-sm">{feature.name}</p>
                <div className="flex gap-1 flex-shrink-0">
                  <button onClick={() => setEditFeature(feature)} className="p-1 text-gray-400 hover:text-gray-600"><Pencil className="w-3.5 h-3.5" /></button>
                  <button onClick={() => setDeleteFeature(feature)} className="p-1 text-gray-400 hover:text-red-500"><Trash2 className="w-3.5 h-3.5" /></button>
                </div>
              </div>
              {feature.description && <p className="text-xs text-gray-500 line-clamp-2">{feature.description}</p>}
              <div className="mt-auto">
                <span className={`${STATUS_BADGE[feature.status] ?? 'badge-blue'} text-xs`}>{STATUS_LABELS[feature.status]}</span>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Nouvelle feature">
        <FeatureForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} />
      </Modal>

      <Modal open={!!editFeature} onClose={() => setEditFeature(null)} title="Modifier la feature">
        {editFeature && (
          <FeatureForm initial={{ name: editFeature.name, description: editFeature.description ?? '', status: editFeature.status }}
            onSubmit={(d) => updateMutation.mutate({ id: editFeature.id, data: d })}
            loading={updateMutation.isPending} onCancel={() => setEditFeature(null)} />
        )}
      </Modal>

      <ConfirmDialog open={!!deleteFeature} onClose={() => setDeleteFeature(null)}
        onConfirm={() => deleteFeature && deleteMutation.mutate(deleteFeature.id)}
        message={`Supprimer la feature "${deleteFeature?.name}" ?`}
        loading={deleteMutation.isPending} />
    </>
  )
}
