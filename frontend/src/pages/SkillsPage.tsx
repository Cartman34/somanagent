/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState } from 'react'
import { Routes, Route, useParams, Link, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, BookOpen, ArrowLeft, Pencil, Trash2, Download, Save } from 'lucide-react'
import { skillsApi } from '@/api/skills'
import type { SkillCreatePayload } from '@/api/skills'
import type { Skill } from '@/types'
import { useTranslation } from '@/hooks/useTranslation'
import { useToast } from '@/hooks/useToast'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'

const SKILLS_PAGE_TRANSLATION_KEYS = [
  'common.action.cancel',
  'common.action.refresh',
  'skill.list.loading',
  'skill.page.title',
  'skill.page.description',
  'skill.page.empty_title',
  'skill.page.empty_description',
  'skill.page.section_imported',
  'skill.page.section_custom',
  'skill.form.identifier_label',
  'skill.form.identifier_placeholder',
  'skill.form.identifier_hint',
  'skill.form.name_label',
  'skill.form.name_placeholder',
  'skill.form.slug_label',
  'skill.form.slug_placeholder',
  'skill.form.description_label',
  'skill.form.description_placeholder',
  'skill.form.content_label',
  'skill.action.import',
  'skill.action.import_loading',
  'skill.action.create',
  'skill.action.create_loading',
  'skill.action.delete_confirm',
  'skill.action.edit_content',
  'skill.action.delete',
  'skill.modal.import_title',
  'skill.modal.create_title',
  'skill.editor.not_found',
  'skill.editor.saved',
  'skill.editor.saving',
  'skill.editor.save',
  'skill.editor.back_link',
  'toast.created',
  'toast.deleted',
] as const

// ─── Import Form ──────────────────────────────────────────────────────────────

function ImportForm({
  onSubmit,
  loading,
  onCancel,
  t,
}: {
  onSubmit: (ownerAndName: string) => void
  loading: boolean
  onCancel: () => void
  t: (key: string) => string
}) {
  const [value, setValue] = useState('')

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit(value.trim()) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          {t('skill.form.identifier_label')}
        </label>
        <input
          className="input font-mono"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          required
          placeholder={t('skill.form.identifier_placeholder')}
        />
        <p className="text-xs text-gray-400 mt-1">
          {t('skill.form.identifier_hint')}
        </p>
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">{t('common.action.cancel')}</button>
        <button type="submit" className="btn-primary" disabled={loading}>
          {loading ? t('skill.action.import_loading') : <><Download className="w-4 h-4" /> {t('skill.action.import')}</>}
        </button>
      </div>
    </form>
  )
}

// ─── Create Custom Skill Form ─────────────────────────────────────────────────

const DEFAULT_CONTENT = `---
name: My Skill
description: What this skill does
version: "1.0"
---

# My Skill

Describe the skill here in Markdown.

## Instructions

1. Step one
2. Step two
`

function CreateSkillForm({
  onSubmit,
  loading,
  onCancel,
  t,
}: {
  onSubmit: (d: SkillCreatePayload) => void
  loading: boolean
  onCancel: () => void
  t: (key: string) => string
}) {
  const [name, setName] = useState('')
  const [slug, setSlug] = useState('')
  const [description, setDescription] = useState('')
  const [content, setContent] = useState(DEFAULT_CONTENT)

  const autoSlug = (n: string) =>
    n.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')

  const handleNameChange = (n: string) => {
    setName(n)
    if (!slug || slug === autoSlug(name)) {
      setSlug(autoSlug(n))
    }
  }

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ name, slug, description: description || undefined, content }) }} className="space-y-4">
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">{t('skill.form.name_label')}</label>
          <input className="input" value={name} onChange={(e) => handleNameChange(e.target.value)} required placeholder={t('skill.form.name_placeholder')} />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">{t('skill.form.slug_label')}</label>
          <input className="input font-mono" value={slug} onChange={(e) => setSlug(e.target.value)} required placeholder={t('skill.form.slug_placeholder')} />
        </div>
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">{t('skill.form.description_label')}</label>
        <input className="input" value={description} onChange={(e) => setDescription(e.target.value)} placeholder={t('skill.form.description_placeholder')} />
      </div>
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">{t('skill.form.content_label')}</label>
        <textarea
          className="input font-mono text-xs resize-none"
          rows={12}
          value={content}
          onChange={(e) => setContent(e.target.value)}
        />
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">{t('common.action.cancel')}</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? t('skill.action.create_loading') : t('skill.action.create')}</button>
      </div>
    </form>
  )
}

// ─── Skills List ──────────────────────────────────────────────────────────────

function SkillsList() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { t } = useTranslation(SKILLS_PAGE_TRANSLATION_KEYS)
  const { toast } = useToast()

  const [importOpen, setImportOpen] = useState(false)
  const [createOpen, setCreateOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState<Skill | null>(null)
  const [importError, setImportError] = useState<string | null>(null)

  const { data: skills, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['skills'],
    queryFn: skillsApi.list,
  })

  const importMutation = useMutation({
    mutationFn: skillsApi.import,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['skills'] })
      setImportOpen(false)
      setImportError(null)
      toast.success(t('toast.created'), 'skill-import')
    },
    onError: (e: Error) => setImportError(e.message),
  })

  const createMutation = useMutation({
    mutationFn: skillsApi.create,
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['skills'] }); setCreateOpen(false); toast.success(t('toast.created'), 'skill-create') },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => skillsApi.delete(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['skills'] }); setDeleteTarget(null); toast.success(t('toast.deleted'), 'skill-delete') },
  })

  if (isLoading) return <PageSpinner />
  if (error) return <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />

  const imported = skills?.filter((s) => s.source === 'imported') ?? []
  const custom = skills?.filter((s) => s.source === 'custom') ?? []

  return (
    <>
      <PageHeader
        title={t('skill.page.title')}
        description={t('skill.page.description')}
        onRefresh={() => qc.invalidateQueries({ queryKey: ['skills'] })}
        refreshTitle={t('common.action.refresh')}
        action={
          <div className="flex gap-2">
            <button className="btn-secondary" onClick={() => setImportOpen(true)}>
              <Download className="w-4 h-4" /> {t('skill.action.import')}
            </button>
            <button className="btn-primary" onClick={() => setCreateOpen(true)}>
              <Plus className="w-4 h-4" /> {t('skill.action.create')}
            </button>
          </div>
        }
      />

      {skills?.length === 0 ? (
        <EmptyState
          icon={BookOpen}
          title={t('skill.page.empty_title')}
          description={t('skill.page.empty_description')}
          action={
            <div className="flex gap-2">
              <button className="btn-secondary" onClick={() => setImportOpen(true)}><Download className="w-4 h-4" /> {t('skill.action.import')}</button>
              <button className="btn-primary" onClick={() => setCreateOpen(true)}><Plus className="w-4 h-4" /> {t('skill.action.create')}</button>
            </div>
          }
        />
      ) : (
        <div className="relative">
          <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={t('skill.list.loading')} />
          <div className="space-y-6">
          {/* Imported */}
          {imported.length > 0 && (
            <section>
              <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
                {t('skill.page.section_imported')} ({imported.length})
              </h2>
              <div className="list-skill grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {imported.map((skill) => (
                  <SkillCard key={skill.id} skill={skill} onEdit={() => navigate(`/skills/${skill.id}/edit`)} onDelete={() => setDeleteTarget(skill)} t={t} />
                ))}
              </div>
            </section>
          )}

          {/* Custom */}
          {custom.length > 0 && (
            <section>
              <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
                {t('skill.page.section_custom')} ({custom.length})
              </h2>
              <div className="list-skill grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {custom.map((skill) => (
                  <SkillCard key={skill.id} skill={skill} onEdit={() => navigate(`/skills/${skill.id}/edit`)} onDelete={() => setDeleteTarget(skill)} t={t} />
                ))}
              </div>
            </section>
          )}
        </div>
        </div>
      )}

      {/* Import modal */}
      <Modal open={importOpen} onClose={() => { setImportOpen(false); setImportError(null) }} title={t('skill.modal.import_title')}>
        {importError && (
          <div className="mb-4 p-3 rounded-lg bg-red-50 text-sm text-red-700">{importError}</div>
        )}
        <ImportForm
          onSubmit={(v) => importMutation.mutate(v)}
          loading={importMutation.isPending}
          onCancel={() => { setImportOpen(false); setImportError(null) }}
          t={t}
        />
      </Modal>

      {/* Create modal */}
      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title={t('skill.modal.create_title')} size="lg">
        <CreateSkillForm
          onSubmit={(d) => createMutation.mutate(d)}
          loading={createMutation.isPending}
          onCancel={() => setCreateOpen(false)}
          t={t}
        />
      </Modal>

      {/* Delete confirm */}
      <ConfirmDialog
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
        message={deleteTarget ? t('skill.action.delete_confirm').replace('%name%', deleteTarget.name) : ''}
        loading={deleteMutation.isPending}
      />
    </>
  )
}

// ─── Skill Card ───────────────────────────────────────────────────────────────

function SkillCard({ skill, onEdit, onDelete, t }: { skill: Skill; onEdit: () => void; onDelete: () => void; t: (key: string) => string }) {
  return (
    <div
      role="button"
      tabIndex={0}
      onClick={onEdit}
      onKeyDown={(e) => e.key === 'Enter' && onEdit()}
      className="item-skill card p-4 flex flex-col gap-2 cursor-pointer hover:shadow-md transition-shadow"
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-medium text-gray-900 truncate">{skill.name}</p>
          <p className="text-xs font-mono text-gray-400">{skill.slug}</p>
        </div>
        <div className="flex gap-1 flex-shrink-0" onClick={(e) => e.stopPropagation()}>
          <button onClick={onEdit} className="p-1.5 text-gray-400 hover:text-brand-600" title={t('skill.action.edit_content')}>
            <Pencil className="w-3.5 h-3.5" />
          </button>
          <button onClick={onDelete} className="p-1.5 text-gray-400 hover:text-red-500" title={t('skill.action.delete')}>
            <Trash2 className="w-3.5 h-3.5" />
          </button>
        </div>
      </div>
      {skill.description && <p className="text-xs text-gray-500 line-clamp-2">{skill.description}</p>}
      <div className="mt-auto pt-1">
        <span className={skill.source === 'imported' ? 'badge-blue' : 'badge-green'}>
          {skill.sourceLabel}
        </span>
      </div>
    </div>
  )
}

// ─── Skill Content Editor ─────────────────────────────────────────────────────

function SkillEditor() {
  const { id } = useParams<{ id: string }>()
  const qc = useQueryClient()
  const { t } = useTranslation(SKILLS_PAGE_TRANSLATION_KEYS)
  const [content, setContent] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  const { data: skill, isLoading, error, refetch } = useQuery({
    queryKey: ['skills', id],
    queryFn: () => skillsApi.get(id!),
    enabled: !!id,
  })

  // Initialize content from fetched skill
  if (skill && content === null) {
    setContent(skill.content ?? '')
  }

  const saveMutation = useMutation({
    mutationFn: (c: string) => skillsApi.updateContent(id!, c),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['skills', id] })
      setSaved(true)
      setTimeout(() => setSaved(false), 2000)
    },
  })

  if (isLoading) return <PageSpinner />
  if (error || !skill) return <ErrorMessage message={(error as Error)?.message ?? t('skill.editor.not_found')} onRetry={() => refetch()} />

  return (
    <>
      <div className="flex items-center justify-between mb-4">
        <Link to="/skills" className="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
          <ArrowLeft className="w-4 h-4" /> {t('skill.editor.back_link')}
        </Link>
        <div className="flex items-center gap-3">
          {saved && <span className="text-sm text-green-600 font-medium">{t('skill.editor.saved')}</span>}
          <button
            className="btn-primary"
            onClick={() => saveMutation.mutate(content ?? '')}
            disabled={saveMutation.isPending}
          >
            <Save className="w-4 h-4" />
            {saveMutation.isPending ? t('skill.editor.saving') : t('skill.editor.save')}
          </button>
        </div>
      </div>

      <div className="mb-4">
        <h1 className="text-2xl font-bold text-gray-900">{skill.name}</h1>
        <div className="flex items-center gap-2 mt-1">
          <span className="font-mono text-sm text-gray-400">{skill.slug}</span>
          <span className={skill.source === 'imported' ? 'badge-blue' : 'badge-green'}>{skill.sourceLabel}</span>
        </div>
      </div>

      <div className="card overflow-hidden">
        <div className="flex items-center gap-2 px-4 py-2 border-b border-gray-200 bg-gray-50">
          <BookOpen className="w-4 h-4 text-gray-400" />
          <span className="text-sm font-medium text-gray-600">SKILL.md</span>
        </div>
        <textarea
          className="w-full p-4 font-mono text-sm text-gray-800 resize-none focus:outline-none"
          rows={30}
          value={content ?? ''}
          onChange={(e) => setContent(e.target.value)}
          spellCheck={false}
        />
      </div>
    </>
  )
}

// ─── Page router ──────────────────────────────────────────────────────────────

/**
 * Skills management page — routes to skills list and skill editor views.
 */
export default function SkillsPage() {
  return (
    <Routes>
      <Route index element={<SkillsList />} />
      <Route path=":id/edit" element={<SkillEditor />} />
    </Routes>
  )
}
