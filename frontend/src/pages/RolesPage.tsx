/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, UserCog, Pencil, Trash2 } from 'lucide-react'
import { rolesApi } from '@/api/roles'
import { useTranslation } from '@/hooks/useTranslation'
import type { RolePayload } from '@/api/roles'
import type { Role } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'

const ROLES_PAGE_TRANSLATION_KEYS = [
  'common.action.refresh',
  'role.list.loading',
  'role.page.title',
  'role.page.description',
  'role.action.new',
  'role.empty.title',
  'role.empty.description',
  'role.action.edit',
  'role.action.delete',
  'role.modal.create.title',
  'role.modal.edit.title',
  'role.confirm.delete.message',
  'role.form.name.label',
  'role.form.name.placeholder',
  'role.form.slug.label',
  'role.form.slug.help',
  'role.form.description.label',
  'role.action.cancel',
  'role.action.saving',
  'role.action.save',
] as const

function RoleForm({ initial, onSubmit, loading, onCancel }: {
  initial?: Partial<RolePayload>
  onSubmit: (d: RolePayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const { t } = useTranslation(ROLES_PAGE_TRANSLATION_KEYS)
  const [slug, setSlug]               = useState(initial?.slug ?? '')
  const [name, setName]               = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')

  const autoSlug = (n: string) => n.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ slug, name, description: description || undefined }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">{t('role.form.name.label')} *</label>
        <input className="input" value={name} onChange={(e) => { setName(e.target.value); if (!initial?.slug) setSlug(autoSlug(e.target.value)) }} required placeholder={t('role.form.name.placeholder')} />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">{t('role.form.slug.label')} *</label>
        <input className="input font-mono text-sm" value={slug} onChange={(e) => setSlug(e.target.value)} required placeholder="dev-php" />
        <p className="text-xs text-gray-400 mt-1">{t('role.form.slug.help')}</p>
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">{t('role.form.description.label')}</label>
        <textarea className="input resize-none" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">{t('role.action.cancel')}</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? t('role.action.saving') : t('role.action.save')}</button>
      </div>
    </form>
  )
}

/**
 * Roles management page — list, create, edit, and delete roles.
 */
export default function RolesPage() {
  const { t } = useTranslation(ROLES_PAGE_TRANSLATION_KEYS)
  const qc = useQueryClient()
  const [createOpen, setCreateOpen]   = useState(false)
  const [editRole, setEditRole]       = useState<Role | null>(null)
  const [deleteRole, setDeleteRole]   = useState<Role | null>(null)

  const { data: roles, isLoading, isFetching, error, refetch } = useQuery({ queryKey: ['roles'], queryFn: rolesApi.list })

  const createMutation = useMutation({ mutationFn: rolesApi.create, onSuccess: () => { qc.invalidateQueries({ queryKey: ['roles'] }); setCreateOpen(false) } })
  const updateMutation = useMutation({ mutationFn: ({ id, data }: { id: string; data: RolePayload }) => rolesApi.update(id, data), onSuccess: () => { qc.invalidateQueries({ queryKey: ['roles'] }); setEditRole(null) } })
  const deleteMutation = useMutation({ mutationFn: (id: string) => rolesApi.delete(id), onSuccess: () => { qc.invalidateQueries({ queryKey: ['roles'] }); setDeleteRole(null) } })

  if (isLoading) return <PageSpinner />
  if (error) return <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />

  return (
    <>
      <PageHeader title={t('role.page.title')} description={t('role.page.description')}
        onRefresh={() => qc.invalidateQueries({ queryKey: ['roles'] })}
        refreshTitle={t('common.action.refresh')}
        action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> {t('role.action.new')}</button>} />

      {roles?.length === 0 ? (
        <EmptyState icon={UserCog} title={t('role.empty.title')} description={t('role.empty.description')}
          action={<button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> {t('role.action.new')}</button>} />
      ) : (
        <div className="relative">
          <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={t('role.list.loading')} />
          <div className="list-role card divide-y divide-gray-100">
            {roles?.map((role) => (
              <div key={role.id} className="item-role flex items-center gap-3 px-4 py-3">
                <UserCog className="w-4 h-4 text-gray-400 flex-shrink-0" />
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-gray-900">{role.name}</p>
                  <p className="text-xs text-gray-400 font-mono">{role.slug}</p>
                  {role.description && <p className="text-xs text-gray-500 truncate">{role.description}</p>}
                </div>
                {role.skills && role.skills.length > 0 && (
                  <div className="hidden sm:flex gap-1 flex-wrap max-w-xs">
                    {role.skills.map((s) => <span key={s.id} className="badge-blue text-xs">{s.name}</span>)}
                  </div>
                )}
                <div className="flex gap-1 flex-shrink-0">
                  <button onClick={() => setEditRole(role)} className="p-1.5 text-gray-400 hover:text-gray-600" title={t('role.action.edit')}><Pencil className="w-3.5 h-3.5" /></button>
                  <button onClick={() => setDeleteRole(role)} className="p-1.5 text-gray-400 hover:text-red-500" title={t('role.action.delete')}><Trash2 className="w-3.5 h-3.5" /></button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title={t('role.modal.create.title')}>
        <RoleForm onSubmit={(d) => createMutation.mutate(d)} loading={createMutation.isPending} onCancel={() => setCreateOpen(false)} />
      </Modal>

      <Modal open={!!editRole} onClose={() => setEditRole(null)} title={t('role.modal.edit.title')}>
        {editRole && (
          <RoleForm initial={{ slug: editRole.slug, name: editRole.name, description: editRole.description ?? '' }}
            onSubmit={(d) => updateMutation.mutate({ id: editRole.id, data: d })}
            loading={updateMutation.isPending} onCancel={() => setEditRole(null)} />
        )}
      </Modal>

      <ConfirmDialog open={!!deleteRole} onClose={() => setDeleteRole(null)}
        onConfirm={() => deleteRole && deleteMutation.mutate(deleteRole.id)}
        message={t('role.confirm.delete.message', { name: deleteRole?.name ?? '' })}
        loading={deleteMutation.isPending} />
    </>
  )
}
