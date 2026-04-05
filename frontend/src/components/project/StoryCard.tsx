/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import {
  XCircle, CheckCircle, Clock, AlertTriangle, ChevronRight,
  GitBranch, User, ArrowRight,
} from 'lucide-react'
import { useTranslation } from '@/hooks/useTranslation'
import type { Ticket, TaskStatus } from '@/types'
import { CATALOG_DOMAIN } from '@/lib/catalog'
import {
  TYPE_BADGE, TYPE_LABEL_KEYS, PRIORITY_COLOR, PRIORITY_LABEL_KEYS,
} from '@/lib/project/constants'

const STORY_CARD_APP_TRANSLATION_KEYS = [
  'common.action.delete',
  'story.card.active_subtasks',
] as const

const STORY_CARD_CATALOG_TRANSLATION_KEYS = [
  ...Object.values(TYPE_LABEL_KEYS),
  ...Object.values(PRIORITY_LABEL_KEYS),
] as const

// ─── Internal helpers ─────────────────────────────────────────────────────────

function StatusIcon({ status }: { status: TaskStatus }) {
  if (status === 'done')       return <CheckCircle className="w-4 h-4 text-green-500" />
  if (status === 'cancelled')  return <XCircle className="w-4 h-4 text-gray-400" />
  if (status === 'in_progress' || status === 'review') return <Clock className="w-4 h-4 text-blue-500" />
  if (status === 'backlog')    return <AlertTriangle className="w-4 h-4 text-gray-300" />
  return <ChevronRight className="w-4 h-4 text-gray-400" />
}

// ─── Component ────────────────────────────────────────────────────────────────

/**
 * Kanban card for a user story or bug.
 * Shows type badge, priority, branch name, assigned role, active-column subtasks,
 * and allowed story transitions as buttons.
 *
 * @see StoryBoard
 */
export default function StoryCard({ ticket, onTransition, onDelete, onOpen, transitioning, progressBlockedReason }: {
  ticket: Ticket
  onTransition: (ticket: Ticket) => void
  onDelete: (ticket: Ticket) => void
  onOpen: (ticket: Ticket) => void
  transitioning: boolean
  progressBlockedReason: string | null
}) {
  const { t } = useTranslation(STORY_CARD_APP_TRANSLATION_KEYS)
  const { t: tc } = useTranslation(STORY_CARD_CATALOG_TRANSLATION_KEYS, CATALOG_DOMAIN)

  const progressionBlocked = progressBlockedReason !== null
  const activeSubtasks = ticket.activeStepTasks ?? []

  return (
    <div className="item-ticket card p-3 space-y-2 text-sm">
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-1.5 flex-wrap">
          <span className={`${TYPE_BADGE[ticket.type]} text-xs`}>{tc(TYPE_LABEL_KEYS[ticket.type])}</span>
          <span className={`text-xs font-medium ${PRIORITY_COLOR[ticket.priority]}`}>{tc(PRIORITY_LABEL_KEYS[ticket.priority])}</span>
        </div>
        <button onClick={() => onDelete(ticket)} className="p-0.5 text-gray-300 hover:text-red-400 flex-shrink-0" title={t('common.action.delete')}>
          <XCircle className="w-3.5 h-3.5" />
        </button>
      </div>

      <button
        className="text-left font-medium leading-snug hover:underline w-full"
        style={{ color: 'var(--text)' }}
        onClick={() => onOpen(ticket)}
      >{ticket.title}</button>

      {ticket.branchName && (
        <div className="flex items-center gap-1 text-xs" style={{ color: 'var(--muted)' }}>
          <GitBranch className="w-3 h-3" />
          {ticket.branchUrl ? (
            <a href={ticket.branchUrl} target="_blank" rel="noreferrer" className="truncate hover:underline" style={{ color: 'var(--brand)' }}>
              <code className="font-mono">{ticket.branchName}</code>
            </a>
          ) : (
            <code className="font-mono truncate">{ticket.branchName}</code>
          )}
        </div>
      )}
      {ticket.assignedRole && (
        <div className="flex items-center gap-1 text-xs" style={{ color: 'var(--muted)' }}>
          <User className="w-3 h-3" /><span>{ticket.assignedRole.name}</span>
        </div>
      )}
      {activeSubtasks.length > 0 && (
        <div className="space-y-1 rounded border px-2 py-2" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
          <p className="text-[11px] font-medium uppercase tracking-wide" style={{ color: 'var(--muted)' }}>
            {t('story.card.active_subtasks')}
          </p>
          <div className="list-ticket-task space-y-1">
            {activeSubtasks.map((subtask) => (
              <div key={subtask.id} className="item-ticket-task flex items-center gap-2 text-xs">
                <StatusIcon status={subtask.status} />
                <span className="truncate" style={{ color: 'var(--text)' }}>{subtask.title}</span>
              </div>
            ))}
          </div>
        </div>
      )}
      {ticket.workflowStepAllowedTransitions.length > 0 && (
        <div className="flex flex-wrap gap-1 pt-1 border-t" style={{ borderColor: 'var(--border)' }}>
          {ticket.workflowStepAllowedTransitions.map((next) => (
            <button
              key={next.id}
              onClick={() => onTransition(ticket)}
              disabled={transitioning || progressionBlocked}
              className="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded border hover:border-[var(--brand)] hover:text-[var(--brand)] transition-colors disabled:opacity-40 disabled:cursor-wait"
              style={{ borderColor: 'var(--border)', color: 'var(--muted)' }}
              title={progressBlockedReason ?? undefined}
            >
              <ArrowRight className="w-2.5 h-2.5" />
              {transitioning ? '…' : next.name}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
