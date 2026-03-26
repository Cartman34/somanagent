import { useQuery } from '@tanstack/react-query'
import { Coins } from 'lucide-react'
import { tokensApi } from '@/api/tokens'
import { agentsApi } from '@/api/agents'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import PageHeader from '@/components/ui/PageHeader'

function fmt(n: number | string) {
  return Number(n).toLocaleString('fr-FR')
}

export default function TokensPage() {
  const { data: summary, isLoading, error, refetch } = useQuery({ queryKey: ['tokens-summary'], queryFn: () => tokensApi.summary() })
  const { data: agents } = useQuery({ queryKey: ['agents'], queryFn: agentsApi.list })

  const agentMap = Object.fromEntries(agents?.map((a) => [a.id, a.name]) ?? [])

  if (isLoading) return <PageSpinner />
  if (error) return <ErrorMessage message={(error as Error).message} onRetry={() => refetch()} />

  const total = summary?.total

  return (
    <>
      <PageHeader title="Tokens" description="Suivi de la consommation de tokens par agent et par modèle." />

      {/* Totaux */}
      <div className="grid grid-cols-3 gap-4 mb-8">
        {[
          { label: 'Tokens en entrée',  value: total?.input  ?? 0 },
          { label: 'Tokens en sortie',  value: total?.output ?? 0 },
          { label: 'Total appels API',  value: total?.calls  ?? 0 },
        ].map(({ label, value }) => (
          <div key={label} className="card p-5">
            <p className="text-sm text-gray-500 mb-1">{label}</p>
            <p className="text-2xl font-bold text-gray-900">{fmt(value)}</p>
          </div>
        ))}
      </div>

      {/* Par agent */}
      <h2 className="text-base font-semibold text-gray-900 mb-3">Répartition par agent</h2>

      {!summary?.byAgent?.length ? (
        <div className="card p-8 text-center">
          <Coins className="w-10 h-10 mx-auto mb-3 text-gray-300" />
          <p className="text-sm text-gray-400">Aucune consommation enregistrée.</p>
        </div>
      ) : (
        <div className="card overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Agent</th>
                <th className="text-right px-4 py-3 font-medium text-gray-600">Entrée</th>
                <th className="text-right px-4 py-3 font-medium text-gray-600">Sortie</th>
                <th className="text-right px-4 py-3 font-medium text-gray-600">Total</th>
                <th className="text-right px-4 py-3 font-medium text-gray-600">Appels</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {summary.byAgent.map((row) => {
                const total = Number(row.totalInput) + Number(row.totalOutput)
                return (
                  <tr key={row.agentId} className="hover:bg-gray-50">
                    <td className="px-4 py-3 font-medium text-gray-900">
                      {agentMap[row.agentId] ?? <span className="text-gray-400 font-mono text-xs">{row.agentId.slice(0, 8)}…</span>}
                    </td>
                    <td className="px-4 py-3 text-right text-gray-600 tabular-nums">{fmt(row.totalInput)}</td>
                    <td className="px-4 py-3 text-right text-gray-600 tabular-nums">{fmt(row.totalOutput)}</td>
                    <td className="px-4 py-3 text-right font-semibold text-gray-900 tabular-nums">{fmt(total)}</td>
                    <td className="px-4 py-3 text-right text-gray-500 tabular-nums">{fmt(row.calls)}</td>
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
