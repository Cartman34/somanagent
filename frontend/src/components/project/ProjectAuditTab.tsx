/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { History } from 'lucide-react'
import { projectsApi } from '@/api/projects'
import { useTranslation } from '@/hooks/useTranslation'
import { AUDIT_ACTION_LABEL_KEYS } from '@/lib/project/constants'
import { PageSpinner } from '@/components/ui/Spinner'
import EmptyState from '@/components/ui/EmptyState'

const AUDIT_TAB_TRANSLATION_KEYS = [
  'audit.list.empty_title',
  'audit.list.empty_description',
  'audit.list.col_entity',
  'audit.list.col_date',
  'project.executionHistory.entries_label',
  'project.executionHistory.prev',
  'project.executionHistory.next',
  ...Object.values(AUDIT_ACTION_LABEL_KEYS),
] as const

/**
 * Audit tab — paginated list of audit entries for the project.
 *
 * @see projectsApi.getAudit
 * @see AUDIT_ACTION_LABEL_KEYS
 */
export default function ProjectAuditTab({ projectId }: { projectId: string }) {
  const [auditPage, setAuditPage] = useState(1)

  const { data: auditData, isLoading: loadingAudit } = useQuery({
    queryKey: ['project-audit', projectId, auditPage],
    queryFn:  () => projectsApi.getAudit(projectId, auditPage),
  })

  const { t, formatDate } = useTranslation(AUDIT_TAB_TRANSLATION_KEYS)

  if (loadingAudit) return <PageSpinner />

  if (!auditData || auditData.data.length === 0) {
    return (
      <EmptyState
        icon={History}
        title={t('audit.list.empty_title')}
        description={t('audit.list.empty_description')}
      />
    )
  }

  const totalPages = Math.ceil(auditData.total / auditData.limit)

  return (
    <div className="space-y-3">
      <div className="list-audit-log card divide-y" style={{ borderColor: 'var(--border)' }}>
        {auditData.data.map((entry) => (
          <div key={entry.id} className="item-audit-log px-4 py-3 flex items-start gap-3">
            <div className="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5" style={{ background: 'var(--brand-dim)' }}>
              <History className="w-3.5 h-3.5" style={{ color: 'var(--brand)' }} />
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-medium" style={{ color: 'var(--text)' }}>
                {t(AUDIT_ACTION_LABEL_KEYS[entry.action] ?? entry.action)}
              </p>
              <div className="flex items-center gap-2 mt-0.5">
                <span className="text-xs" style={{ color: 'var(--muted)' }}>{t('audit.list.col_entity')}: {entry.entityType}</span>
                {entry.data && Object.keys(entry.data).length > 0 && (
                  <span className="text-xs" style={{ color: 'var(--muted)' }}>
                    {Object.entries(entry.data).map(([k, v]) => `${k}: ${String(v)}`).join(', ')}
                  </span>
                )}
              </div>
            </div>
            <span className="text-xs flex-shrink-0" style={{ color: 'var(--muted)' }}>
              {t('audit.list.col_date')}: {formatDate(entry.createdAt)}
            </span>
          </div>
        ))}
      </div>

      {auditData.total > auditData.limit && (
        <div className="flex items-center justify-between text-sm" style={{ color: 'var(--muted)' }}>
          <span>{auditData.total} {t('project.executionHistory.entries_label')}</span>
          <div className="flex gap-2">
            <button
              className="btn-secondary py-1"
              disabled={auditPage <= 1}
              onClick={() => setAuditPage((p) => p - 1)}
            >{t('project.executionHistory.prev')}</button>
            <span className="px-2 py-1">{auditPage} / {totalPages}</span>
            <button
              className="btn-secondary py-1"
              disabled={auditPage >= totalPages}
              onClick={() => setAuditPage((p) => p + 1)}
            >{t('project.executionHistory.next')}</button>
          </div>
        </div>
      )}
    </div>
  )
}
