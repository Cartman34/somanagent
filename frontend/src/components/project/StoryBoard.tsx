/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { Layers } from 'lucide-react'
import type { Ticket } from '@/types'
import EmptyState from '@/components/ui/EmptyState'
import StoryCard from '@/components/project/StoryCard'

/**
 * Kanban board with one column per workflow step.
 * The `pendingTaskId` disables transition buttons on the card being updated to prevent double-clicks.
 *
 * @see StoryCard
 */
export default function StoryBoard({ tickets, steps, onTransition, onDelete, onOpen, pendingTaskId, progressBlockedReason }: {
  tickets: Ticket[]
  steps: Array<{ id: string; key: string; name: string }>
  onTransition: (ticket: Ticket) => void
  onDelete: (ticket: Ticket) => void
  onOpen: (ticket: Ticket) => void
  pendingTaskId: string | null
  progressBlockedReason: string | null
}) {
  if (tickets.length === 0) {
    return <EmptyState icon={Layers} title="Aucune story ni bug" description="Créez une demande via le bouton ci-dessus pour l'envoyer au Product Owner." />
  }
  return (
    <div className="flex gap-3 overflow-x-auto pb-4">
      {steps.map((col, index) => {
        const accent = ['#94a3b8', '#3b82f6', '#8b5cf6', '#ec4899', '#f97316', '#eab308', '#22c55e'][index % 7]
        const cards = tickets.filter((s) => s.workflowStep?.key === col.key)
        return (
          <div key={col.key} className="flex-shrink-0 w-60">
            <div
              className="flex items-center justify-between rounded-t-lg border border-b-0 px-3 py-1.5"
              style={{
                background: `color-mix(in srgb, ${accent} 14%, var(--surface2))`,
                borderColor: `color-mix(in srgb, ${accent} 32%, var(--border))`,
              }}
            >
              <span className="text-xs font-semibold" style={{ color: accent }}>{col.name}</span>
              {cards.length > 0 && <span className="text-xs font-medium" style={{ color: accent, opacity: 0.72 }}>{cards.length}</span>}
            </div>
            <div className="list-ticket space-y-2 min-h-16 rounded-b-lg p-2 border border-t-0" style={{ background: 'var(--surface2)', borderColor: 'var(--border)' }}>
              {cards.map((ticket) => (
                <StoryCard
                  key={ticket.id}
                  ticket={ticket}
                  onTransition={onTransition}
                  onDelete={onDelete}
                  onOpen={onOpen}
                  transitioning={pendingTaskId === ticket.id}
                  progressBlockedReason={progressBlockedReason}
                />
              ))}
              {cards.length === 0 && <p className="text-xs text-center py-3" style={{ color: 'var(--muted)' }}>—</p>}
            </div>
          </div>
        )
      })}
    </div>
  )
}
