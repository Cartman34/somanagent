/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { XCircle } from 'lucide-react'
import type { Ticket, TicketTask, Workflow } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import StoryBoard from '@/components/project/StoryBoard'

/**
 * Board tab — Kanban board of stories and bugs with workflow step columns.
 * Inline error banners are shown for dispatch and transition errors.
 *
 * @see StoryBoard
 * @see TaskDrawer — opened via the onOpen callback
 */
export default function ProjectBoardTab({
  tickets,
  loadingTickets,
  errorTickets,
  workflow,
  pendingTaskId,
  projectProgressBlockedReason,
  requestDispatchError,
  onClearRequestError,
  transitionError,
  onClearTransitionError,
  onTransition,
  onDelete,
  onOpen,
}: {
  tickets: Ticket[]
  loadingTickets: boolean
  errorTickets: Error | null
  workflow: Workflow | null | undefined
  pendingTaskId: string | null
  projectProgressBlockedReason: string | null
  requestDispatchError: string | null
  onClearRequestError: () => void
  transitionError: string | null
  onClearTransitionError: () => void
  onTransition: (ticket: Ticket) => void
  onDelete: (entity: Ticket | TicketTask) => void
  onOpen: (ticket: Ticket) => void
}) {
  return (
    <>
      {requestDispatchError && (
        <div className="mb-3 px-3 py-2 rounded flex items-center justify-between text-sm" style={{ background: 'rgba(239,68,68,0.1)', color: '#dc2626', border: '1px solid rgba(239,68,68,0.3)' }}>
          <span>L'agent Product Owner n'a pas pu prendre la demande. {requestDispatchError}</span>
          <button onClick={onClearRequestError}><XCircle className="w-4 h-4" /></button>
        </div>
      )}
      {transitionError && (
        <div className="mb-3 px-3 py-2 rounded flex items-center justify-between text-sm" style={{ background: 'rgba(239,68,68,0.1)', color: '#dc2626', border: '1px solid rgba(239,68,68,0.3)' }}>
          <span>{transitionError}</span>
          <button onClick={onClearTransitionError}><XCircle className="w-4 h-4" /></button>
        </div>
      )}

      {loadingTickets ? <PageSpinner /> : errorTickets ? (
        <ErrorMessage message={(errorTickets as Error).message} />
      ) : (
        <StoryBoard
          tickets={tickets}
          steps={Array.isArray(workflow?.steps) ? workflow.steps.map((step) => ({ id: step.id, key: step.outputKey, name: step.name })) : []}
          onTransition={onTransition}
          onDelete={onDelete}
          onOpen={onOpen}
          pendingTaskId={pendingTaskId}
          progressBlockedReason={projectProgressBlockedReason}
        />
      )}
    </>
  )
}
