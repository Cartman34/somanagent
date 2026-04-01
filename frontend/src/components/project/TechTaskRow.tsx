/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import {
  XCircle, CheckCircle, Clock, AlertTriangle, ChevronRight,
} from 'lucide-react'
import type { Ticket, TicketTask, TaskStatus } from '@/types'
import { TYPE_BADGE, TYPE_LABELS, STATUS_LABELS, PRIORITY_COLOR, PRIORITY_LABELS } from '@/lib/project/constants'

// ─── Internal helpers ─────────────────────────────────────────────────────────

function ProgressBar({ value }: { value: number }) {
  return (
    <div className="w-full bg-gray-100 rounded-full h-1">
      <div className="h-1 rounded-full" style={{ width: `${value}%`, background: 'var(--brand)' }} />
    </div>
  )
}

function StatusIcon({ status }: { status: TaskStatus }) {
  if (status === 'done')       return <CheckCircle className="w-4 h-4 text-green-500" />
  if (status === 'cancelled')  return <XCircle className="w-4 h-4 text-gray-400" />
  if (status === 'in_progress' || status === 'review') return <Clock className="w-4 h-4 text-blue-500" />
  if (status === 'backlog')    return <AlertTriangle className="w-4 h-4 text-gray-300" />
  return <ChevronRight className="w-4 h-4 text-gray-400" />
}

/**
 * Single row for a technical task in the Tâches tab.
 * Shows status icon, type badge, title, priority, assigned agent/role, parent story title, and progress bar.
 *
 * @see TaskDrawer — opens when the user clicks a task row
 */
export default function TechTaskRow({
  task,
  parent,
  onDelete,
  onOpen,
}: {
  task: TicketTask
  parent: TicketTask | Ticket | null
  onDelete: (t: TicketTask) => void
  onOpen: (t: TicketTask) => void
}) {
  return (
    <div className="item-ticket-task px-4 py-3 flex items-center gap-3">
      <StatusIcon status={task.status} />
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className={`${TYPE_BADGE.task} text-xs`}>{TYPE_LABELS.task}</span>
          <button className="text-sm font-medium truncate hover:underline text-left" style={{ color: 'var(--text)' }} onClick={() => onOpen(task)}>{task.title}</button>
        </div>
        <div className="flex items-center gap-3 mt-0.5 flex-wrap">
          <span className="text-xs" style={{ color: 'var(--muted)' }}>{STATUS_LABELS[task.status]}</span>
          <span className={`text-xs font-medium ${PRIORITY_COLOR[task.priority]}`}>{PRIORITY_LABELS[task.priority]}</span>
          {task.assignedAgent && <span className="text-xs" style={{ color: 'var(--muted)' }}>→ {task.assignedAgent.name}</span>}
          {task.assignedRole  && <span className="text-xs italic" style={{ color: 'var(--muted)' }}>({task.assignedRole.name})</span>}
          {parent && <span className="text-xs opacity-50" style={{ color: 'var(--muted)' }}>↑ {parent.title}</span>}
        </div>
        {task.progress > 0 && (
          <div className="mt-1.5 flex items-center gap-2">
            <ProgressBar value={task.progress} />
            <span className="text-xs whitespace-nowrap" style={{ color: 'var(--muted)' }}>{task.progress}%</span>
          </div>
        )}
      </div>
      <button onClick={() => onDelete(task)} className="p-1.5 flex-shrink-0" style={{ color: 'var(--muted)' }} title="Supprimer">
        <XCircle className="w-3.5 h-3.5" />
      </button>
    </div>
  )
}
