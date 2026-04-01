/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { Coins } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { projectsApi } from '@/api/projects'
import { PageSpinner } from '@/components/ui/Spinner'
import EmptyState from '@/components/ui/EmptyState'

/**
 * Tokens tab — token consumption summary and recent usage entries for the project.
 *
 * @see projectsApi.getTokens
 */
export default function ProjectTokensTab({ projectId }: { projectId: string }) {
  const { data: tokensData, isLoading: loadingTokens } = useQuery({
    queryKey: ['project-tokens', projectId],
    queryFn:  () => projectsApi.getTokens(projectId),
  })

  if (loadingTokens) return <PageSpinner />
  if (!tokensData) return null

  return (
    <div className="space-y-5">
      {/* Summary */}
      <div className="grid grid-cols-3 gap-3">
        {[
          { label: 'Tokens entrée', value: tokensData.summary.total.input.toLocaleString() },
          { label: 'Tokens sortie', value: tokensData.summary.total.output.toLocaleString() },
          { label: 'Appels',        value: tokensData.summary.total.calls },
        ].map(({ label, value }) => (
          <div key={label} className="card p-4 text-center">
            <p className="text-xl font-bold" style={{ color: 'var(--text)' }}>{value}</p>
            <p className="text-xs mt-0.5" style={{ color: 'var(--muted)' }}>{label}</p>
          </div>
        ))}
      </div>

      {/* By agent */}
      {tokensData.summary.byAgent.length > 0 && (
        <div className="card overflow-hidden">
          <div className="px-4 py-2 border-b text-xs font-semibold" style={{ color: 'var(--muted)', borderColor: 'var(--border)' }}>
            Répartition par agent
          </div>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-xs border-b" style={{ color: 'var(--muted)', borderColor: 'var(--border)' }}>
                <th className="px-4 py-2 text-left font-medium">Agent</th>
                <th className="px-4 py-2 text-right font-medium">Entrée</th>
                <th className="px-4 py-2 text-right font-medium">Sortie</th>
                <th className="px-4 py-2 text-right font-medium">Appels</th>
              </tr>
            </thead>
            <tbody className="divide-y" style={{ borderColor: 'var(--border)' }}>
              {tokensData.summary.byAgent.map((row) => (
                <tr key={row.agentId ?? 'unknown'} className="item-agent">
                  <td className="px-4 py-2 font-medium" style={{ color: 'var(--text)' }}>{row.agentName}</td>
                  <td className="px-4 py-2 text-right" style={{ color: 'var(--muted)' }}>{row.totalInput.toLocaleString()}</td>
                  <td className="px-4 py-2 text-right" style={{ color: 'var(--muted)' }}>{row.totalOutput.toLocaleString()}</td>
                  <td className="px-4 py-2 text-right" style={{ color: 'var(--muted)' }}>{row.calls}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Recent entries */}
      {tokensData.entries.length > 0 && (
        <div className="card overflow-hidden">
          <div className="px-4 py-2 border-b text-xs font-semibold" style={{ color: 'var(--muted)', borderColor: 'var(--border)' }}>
            Entrées récentes
          </div>
          <div className="list-token-usage divide-y" style={{ borderColor: 'var(--border)' }}>
            {tokensData.entries.map((u) => (
              <div key={u.id} className="item-token-usage px-4 py-3 flex items-center gap-3 text-sm">
                <div className="flex-1 min-w-0">
                  <p className="truncate font-medium" style={{ color: 'var(--text)' }}>{u.task?.title ?? '—'}</p>
                  <p className="text-xs" style={{ color: 'var(--muted)' }}>{u.model}</p>
                </div>
                <div className="text-right flex-shrink-0">
                  <p className="font-medium" style={{ color: 'var(--brand)' }}>{u.totalTokens.toLocaleString()} tok</p>
                  {u.durationMs !== null && (
                    <p className="text-xs" style={{ color: 'var(--muted)' }}>{(u.durationMs / 1000).toFixed(1)}s</p>
                  )}
                </div>
                <span className="text-xs flex-shrink-0" style={{ color: 'var(--muted)' }}>
                  {new Date(u.createdAt).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {tokensData.summary.total.calls === 0 && (
        <EmptyState icon={Coins} title="Aucune consommation" description="Les tokens consommés par les agents sur ce projet s'afficheront ici." />
      )}
    </div>
  )
}
