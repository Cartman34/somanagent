/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState } from 'react'
import type { ProjectRequestPayload } from '@/api/tickets'

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

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit({ title, description: description || undefined }) }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Demande *</label>
        <input
          className="input"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          required
          placeholder="Ex: permettre l'export PDF des rapports"
        />
      </div>
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Contexte</label>
        <textarea
          className="input resize-none"
          rows={4}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          placeholder="Décrivez le besoin métier, le problème, les contraintes ou le résultat attendu."
        />
      </div>
      <p className="text-xs" style={{ color: 'var(--muted)' }}>
        Cette demande crée une user story et la transmet automatiquement à un agent Product Owner si le workflow ou l'équipe le permet.
      </p>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Annuler</button>
        <button type="submit" className="btn-primary" disabled={loading}>{loading ? 'Transmission…' : 'Envoyer au PO'}</button>
      </div>
    </form>
  )
}
