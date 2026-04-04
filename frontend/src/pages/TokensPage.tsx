/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Coins } from 'lucide-react'
import { tokensApi } from '@/api/tokens'
import { agentsApi } from '@/api/agents'
import { useTranslation } from '@/hooks/useTranslation'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'

const TOKENS_PAGE_TRANSLATION_KEYS = [
  'token.list.loading',
  'common.action.refresh',
  'tokens.page.title',
  'tokens.page.description',
  'tokens.card.input',
  'tokens.card.output',
  'tokens.card.totalCalls',
  'tokens.section.byAgent',
  'tokens.empty.noConsumption',
  'tokens.table.agent',
  'tokens.table.input',
  'tokens.table.output',
  'tokens.table.total',
  'tokens.table.calls',
] as const

/**
 * Tokens usage page — displays token consumption summary per agent.
 */
export default function TokensPage() {
  const qc = useQueryClient()
  const { data: summary, isLoading, isFetching, error, refetch } = useQuery({ queryKey: ['tokens-summary'], queryFn: () => tokensApi.summary() })
  const { data: agents } = useQuery({ queryKey: ['agents'], queryFn: agentsApi.list })
  const { t, formatNumber } = useTranslation(TOKENS_PAGE_TRANSLATION_KEYS)

  const agentMap = Object.fromEntries(agents?.map((a) => [a.id, a.name]) ?? [])

  if (isLoading) return <PageSpinner />
  if (error) return <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />

  const total = summary?.total

  return (
    <>
      <PageHeader title={t('tokens.page.title')} description={t('tokens.page.description')}
        onRefresh={() => { qc.invalidateQueries({ queryKey: ['tokens-summary'] }); qc.invalidateQueries({ queryKey: ['agents'] }) }}
        refreshTitle={t('common.action.refresh')} />

      {/* Totaux */}
      <div className="relative">
        <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={t('token.list.loading')} />
        <div className="grid grid-cols-3 gap-4 mb-8">
        {[
          { label: t('tokens.card.input'),  value: total?.input  ?? 0 },
          { label: t('tokens.card.output'),  value: total?.output ?? 0 },
          { label: t('tokens.card.totalCalls'),  value: total?.calls  ?? 0 },
        ].map(({ label, value }) => (
          <div key={label} className="card p-5">
            <p className="text-sm text-gray-500 mb-1">{label}</p>
            <p className="text-2xl font-bold text-gray-900">{formatNumber(value)}</p>
          </div>
        ))}
        </div>
      </div>

      {/* Par agent */}
      <h2 className="text-base font-semibold text-gray-900 mb-3">{t('tokens.section.byAgent')}</h2>

      {!summary?.byAgent?.length ? (
        <div className="card p-8 text-center">
          <Coins className="w-10 h-10 mx-auto mb-3 text-gray-300" />
          <p className="text-sm text-gray-400">{t('tokens.empty.noConsumption')}</p>
        </div>
      ) : (
        <div className="list-agent card overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="text-left px-4 py-3 font-medium text-gray-600">{t('tokens.table.agent')}</th>
                <th className="text-right px-4 py-3 font-medium text-gray-600">{t('tokens.table.input')}</th>
                <th className="text-right px-4 py-3 font-medium text-gray-600">{t('tokens.table.output')}</th>
                <th className="text-right px-4 py-3 font-medium text-gray-600">{t('tokens.table.total')}</th>
                <th className="text-right px-4 py-3 font-medium text-gray-600">{t('tokens.table.calls')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {summary.byAgent.map((row) => {
                const total = Number(row.totalInput) + Number(row.totalOutput)
                return (
                  <tr key={row.agentId} className="item-agent hover:bg-gray-50">
                    <td className="px-4 py-3 font-medium text-gray-900">
                      {agentMap[row.agentId] ?? <span className="text-gray-400 font-mono text-xs">{row.agentId.slice(0, 8)}…</span>}
                    </td>
                    <td className="px-4 py-3 text-right text-gray-600 tabular-nums">{formatNumber(Number(row.totalInput))}</td>
                    <td className="px-4 py-3 text-right text-gray-600 tabular-nums">{formatNumber(Number(row.totalOutput))}</td>
                    <td className="px-4 py-3 text-right font-semibold text-gray-900 tabular-nums">{formatNumber(total)}</td>
                    <td className="px-4 py-3 text-right text-gray-500 tabular-nums">{formatNumber(Number(row.calls))}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}
