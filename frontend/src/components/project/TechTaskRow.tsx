/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import {
  XCircle, CheckCircle, Clock, AlertTriangle, ChevronRight,
} from 'lucide-react'
import { useTranslation } from '@/hooks/useTranslation'
import type { Ticket, TicketTask, TaskStatus } from '@/types'
import { TYPE_BADGE, TYPE_LABEL_KEYS, STATUS_LABEL_KEYS, PRIORITY_COLOR, PRIORITY_LABEL_KEYS } from '@/lib/project/constants'
import { CATALOG_DOMAIN } from '@/lib/catalog'

const TECH_TASK_ROW_APP_TRANSLATION_KEYS = [
  'common.action.delete',
] as const

const TECH_TASK_ROW_CATALOG_TRANSLATION_KEYS = [
  ...Object.values(TYPE_LABEL_KEYS),
  ...Object.values(STATUS_LABEL_KEYS),
  ...Object.values(PRIORITY_LABEL_KEYS),
] as const

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
 * Single row for a technical task in the Tasks tab.
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
  const { t } = useTranslation(TECH_TASK_ROW_APP_TRANSLATION_KEYS)
  const { t: tc } = useTranslation(TECH_TASK_ROW_CATALOG_TRANSLATION_KEYS, CATALOG_DOMAIN)

  return (
    <div className="item-ticket-task px-4 py-3 flex items-center gap-3">
      <StatusIcon status={task.status} />
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className={`${TYPE_BADGE.task} text-xs`}>{tc(TYPE_LABEL_KEYS.task)}</span>
          <button className="text-sm font-medium truncate hover:underline text-left" style={{ color: 'var(--text)' }} onClick={() => onOpen(task)}>{task.title}</button>
        </div>
        <div className="flex items-center gap-3 mt-0.5 flex-wrap">
          <span className="text-xs" style={{ color: 'var(--muted)' }}>{tc(STATUS_LABEL_KEYS[task.status])}</span>
          <span className={`text-xs font-medium ${PRIORITY_COLOR[task.priority]}`}>{tc(PRIORITY_LABEL_KEYS[task.priority])}</span>
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
      <button onClick={() => onDelete(task)} className="p-1.5 flex-shrink-0" style={{ color: 'var(--muted)' }} title={t('common.action.delete')}>
        <XCircle className="w-3.5 h-3.5" />
      </button>
    </div>
  )
}
