/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState } from 'react'
import type { TaskType, TaskPriority } from '@/types'
import type { TicketTaskPayload } from '@/api/tickets'

/**
 * Form for creating a new ticket or technical task.
 * When `type` is `'task'`, additional fields for parent ticket and workflow action are shown.
 */
export default function TaskForm({ initial, onSubmit, loading, onCancel, tickets, actions }: {
  initial?: Partial<TicketTaskPayload & { ticketId?: string; type?: TaskType }>
  onSubmit: (d: TicketTaskPayload & { ticketId?: string; type?: TaskType }) => void
  loading: boolean
  onCancel: () => void
  tickets?: Array<{ id: string; title: string }>
  actions?: Array<{ key: string; label: string }>
}) {
  const [title, setTitle]             = useState(initial?.title ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [type, setType]               = useState<TaskType>(initial?.type ?? 'user_story')
  const [priority, setPriority]       = useState<TaskPriority>(initial?.priority ?? 'medium')
  const [ticketId, setTicketId]       = useState(initial?.ticketId ?? '')
  const [actionKey, setActionKey]     = useState(initial?.actionKey ?? '')

  return (
    <form onSubmit={(e) => {
      e.preventDefault()
      onSubmit({
        title,
        description: description || undefined,
        type,
        priority,
        ticketId: type === 'task' ? ticketId || undefined : undefined,
        actionKey: type === 'task' ? actionKey || undefined : undefined,
      })
    }} className="space-y-4">
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Titre *</label>
        <input className="input" value={title} onChange={(e) => setTitle(e.target.value)} required placeholder="En tant qu'utilisateur, je veux..." />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Type</label>
          <select className="input" value={type} onChange={(e) => setType(e.target.value as TaskType)}>
            <option value="user_story">User Story</option>
            <option value="bug">Bug</option>
            <option value="task">Tâche technique</option>
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Priorité</label>
          <select className="input" value={priority} onChange={(e) => setPriority(e.target.value as TaskPriority)}>
            <option value="low">Faible</option>
            <option value="medium">Normale</option>
            <option value="high">Haute</option>
            <option value="critical">Critique</option>
          </select>
        </div>
      </div>
      {type === 'task' && (
        <>
          <div>
            <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Ticket parent</label>
            <select className="input" value={ticketId} onChange={(e) => setTicketId(e.target.value)} required>
              <option value="">— Sélectionner un ticket —</option>
              {tickets?.map((ticket) => (
                <option key={ticket.id} value={ticket.id}>{ticket.title}</option>
              ))}
            </select>
            {(tickets?.length ?? 0) === 0 && (
              <p className="mt-1 text-xs" style={{ color: '#dc2626' }}>Crée d'abord un ticket avant d'ajouter une tâche technique.</p>
            )}
          </div>
          <div>
            <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Action *</label>
            <select className="input" value={actionKey} onChange={(e) => setActionKey(e.target.value)} required>
              <option value="">— Sélectionner une action —</option>
              {actions?.map((action) => (
                <option key={action.key} value={action.key}>{action.label}</option>
              ))}
            </select>
            {(actions?.length ?? 0) === 0 && (
              <p className="mt-1 text-xs" style={{ color: '#dc2626' }}>Aucune action n'est disponible pour ce workflow.</p>
            )}
          </div>
        </>
      )}
      <div>
        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Description</label>
        <textarea className="input resize-none" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
      </div>
      <div className="flex justify-end gap-3 pt-2">
        <button type="button" onClick={onCancel} className="btn-secondary">Annuler</button>
        <button type="submit" className="btn-primary" disabled={loading || (type === 'task' && (!ticketId || !actionKey))}>{loading ? 'Enregistrement…' : 'Enregistrer'}</button>
      </div>
    </form>
  )
}
