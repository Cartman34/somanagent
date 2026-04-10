/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState } from 'react'
import type { ProjectRequestPayload } from '@/api/tickets'
import { useTranslation } from '@/hooks/useTranslation'

const REQUEST_FORM_TRANSLATION_KEYS = [
  'project.request_form.title_label',
  'project.request_form.title_placeholder',
  'project.request_form.context_label',
  'project.request_form.context_placeholder',
  'project.request_form.hint',
  'project.request_form.cancel_button',
  'project.request_form.submit_button',
  'project.request_form.submitting_button',
] as const

/**
 * Form for submitting a new project request (user story via Product Owner agent).
 * Sends title and optional business context to the API.
 *
 * @see ProjectRequestPayload
 */
export default function RequestForm({ onSubmit, loading, onCancel }: {
  onSubmit: (d: ProjectRequestPayload) => void
  loading: boolean
  onCancel: () => void
}) {
  const [title, setTitle]             = useState('')
  const [description, setDescription] = useState('')
  const { t } = useTranslation(REQUEST_FORM_TRANSLATION_KEYS)

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ title, description }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{t('project.request_form.title_label')}</label>
        <input
          className="input"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          required
          placeholder={t('project.request_form.title_placeholder')}
        />
      </div>
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{t('project.request_form.context_label')}</label>
        <textarea
          className="input resize-none"
          rows={4}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          required
          placeholder={t('project.request_form.context_placeholder')}
        />
      </div>
      <p className="text-xs" style={{ color: 'var(--muted)' }}>
        {t('project.request_form.hint')}
      </p>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">{t('project.request_form.cancel_button')}</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? t('project.request_form.submitting_button') : t('project.request_form.submit_button')}</button>
      </div>
    </form>
  )
}
