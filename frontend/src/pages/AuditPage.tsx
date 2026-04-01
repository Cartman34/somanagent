/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ScrollText, ChevronLeft, ChevronRight } from 'lucide-react'
import apiClient from '@/api/client'
import { translationsApi } from '@/api/translations'
import type { AuditLog } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'

const PAGE_SIZE = 25

async function fetchAuditLogs(page: number): Promise<{ data: AuditLog[]; total: number }> {
  const { data } = await apiClient.get('/audit', { params: { page, limit: PAGE_SIZE } })
  return data
}

function fmtDate(date: string) {
  return new Date(date).toLocaleString('fr-FR', {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

const actionColors: Record<string, string> = {
  'project.created': 'badge-green',
  'project.updated': 'badge-blue',
  'project.deleted': 'badge-red',
  'team.created': 'badge-green',
  'team.updated': 'badge-blue',
  'team.deleted': 'badge-red',
  'agent.created': 'badge-green',
  'agent.updated': 'badge-blue',
  'agent.deleted': 'badge-red',
  'skill.imported': 'badge-orange',
  'skill.created': 'badge-green',
  'skill.updated': 'badge-blue',
  'skill.deleted': 'badge-red',
  'workflow.run': 'badge-blue',
  'workflow.created': 'badge-green',
  'workflow.deleted': 'badge-red',
}

function actionColor(action: string) {
  return actionColors[action] ?? 'badge-gray'
}

export default function AuditPage() {
  const qc = useQueryClient()
  const [page, setPage] = useState(1)

  const { data, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['audit', page],
    queryFn: () => fetchAuditLogs(page),
  })

  const { data: auditI18n } = useQuery({
    queryKey: ['ui-translations', 'audit'],
    queryFn: () => translationsApi.list(['audit.list.loading', 'common.action.refresh']),
  })
  const tt = (key: string) => auditI18n?.translations[key] ?? key

  if (isLoading) return <PageSpinner />
  if (error) return <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />

  const logs = data?.data ?? []
  const total = data?.total ?? 0
  const totalPages = Math.ceil(total / PAGE_SIZE)

  return (
    <>
      <PageHeader
        title="Journal d'audit"
        description="Toutes les actions effectuées dans l'application."
        onRefresh={() => qc.invalidateQueries({ queryKey: ['audit'] })}
        refreshTitle={tt('common.action.refresh')}
      />

      {logs.length === 0 ? (
        <EmptyState
          icon={ScrollText}
          title="Aucun événement"
          description="Les événements apparaîtront ici lors de vos interactions avec l'application."
        />
      ) : (
        <div className="relative">
          <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={tt('audit.list.loading')} />
          <div className="list-audit-log card overflow-hidden">
            <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Action</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600 hidden sm:table-cell">Entité</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600 hidden md:table-cell">ID</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Date</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {logs.map((log) => (
                <tr key={log.id} className="item-audit-log hover:bg-gray-50 transition-colors">
                  <td className="px-4 py-3">
                    <span className={actionColor(log.action)}>{log.action}</span>
                  </td>
                  <td className="px-4 py-3 hidden sm:table-cell text-gray-600">{log.entityType}</td>
                  <td className="px-4 py-3 hidden md:table-cell">
                    <span className="font-mono text-xs text-gray-400">{log.entityId ?? '—'}</span>
                  </td>
                  <td className="px-4 py-3 text-gray-500 whitespace-nowrap">{fmtDate(log.createdAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between px-4 py-3 border-t border-gray-200">
              <p className="text-xs text-gray-500">
                {(page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, total)} sur {total} événements
              </p>
              <div className="flex gap-1">
                <button
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page === 1}
                  className="p-1.5 rounded text-gray-500 hover:bg-gray-100 disabled:opacity-30"
                >
                  <ChevronLeft className="w-4 h-4" />
                </button>
                <button
                  onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                  disabled={page === totalPages}
                  className="p-1.5 rounded text-gray-500 hover:bg-gray-100 disabled:opacity-30"
                >
                  <ChevronRight className="w-4 h-4" />
                </button>
              </div>
            </div>
          )}
        </div>
        </div>
      )}
    </>
  )
}
