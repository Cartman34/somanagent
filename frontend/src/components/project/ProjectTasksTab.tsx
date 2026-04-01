/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { AlertTriangle } from 'lucide-react'
import type { Ticket, TicketTask } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import EmptyState from '@/components/ui/EmptyState'
import TechTaskRow from '@/components/project/TechTaskRow'

/**
 * Tasks tab — flat list of all technical tasks for the project.
 *
 * @see TechTaskRow
 * @see TaskDrawer — opened via the onOpen callback
 */
export default function ProjectTasksTab({ techTasks, taskMap, tickets, loadingTickets, onDelete, onOpen }: {
  techTasks: TicketTask[]
  taskMap: Map<string, TicketTask>
  tickets: Ticket[]
  loadingTickets: boolean
  onDelete: (entity: Ticket | TicketTask) => void
  onOpen: (task: TicketTask) => void
}) {
  if (loadingTickets) return <PageSpinner />

  if (techTasks.length === 0) {
    return (
      <EmptyState
        icon={AlertTriangle}
        title="Aucune tâche technique"
        description="Les tâches techniques sont créées automatiquement lors de la planification d'une story, ou manuellement."
      />
    )
  }

  return (
    <div className="list-ticket-task card divide-y" style={{ borderColor: 'var(--border)' }}>
      {techTasks.map((t) => (
        <TechTaskRow
          key={t.id}
          task={t}
          parent={
            t.parentTaskId
              ? (taskMap.get(t.parentTaskId) ?? tickets.find((ticket) => ticket.id === t.ticketId) ?? null)
              : tickets.find((ticket) => ticket.id === t.ticketId) ?? null
          }
          onDelete={onDelete}
          onOpen={onOpen}
        />
      ))}
    </div>
  )
}
