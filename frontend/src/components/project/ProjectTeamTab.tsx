/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { Users, Settings, Zap } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { teamsApi } from '@/api/teams'
import type { Project, AgentSummary } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import EmptyState from '@/components/ui/EmptyState'
import AgentStatusBadge from '@/components/project/AgentStatusBadge'

/**
 * Team tab — lists agents in the team assigned to the project.
 * Clicking an agent card opens the AgentSheet.
 *
 * @see teamsApi.get
 * @see AgentSheet — opened via the onOpenAgent callback
 */
export default function ProjectTeamTab({ project, onOpenAgent, onGoToGeneral }: {
  project: Project
  onOpenAgent: (agentId: string) => void
  onGoToGeneral: () => void
}) {
  const { data: teamDetail } = useQuery({
    queryKey: ['teams', project.team?.id],
    queryFn:  () => teamsApi.get(project.team!.id),
    enabled:  !!project.team?.id,
  })

  if (!project.team) {
    return (
      <EmptyState
        icon={Users}
        title="Aucune équipe assignée"
        description="Assignez une équipe dans l'onglet Général pour voir les agents disponibles."
        action={
          <button className="btn-secondary" onClick={onGoToGeneral}>
            <Settings className="w-4 h-4" /> Configurer
          </button>
        }
      />
    )
  }

  if (!teamDetail) return <PageSpinner />

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3 mb-2">
        <h3 className="text-sm font-semibold" style={{ color: 'var(--text)' }}>{teamDetail.name}</h3>
        {teamDetail.description && (
          <span className="text-sm" style={{ color: 'var(--muted)' }}>{teamDetail.description}</span>
        )}
      </div>

      {(!teamDetail.agents || teamDetail.agents.length === 0) ? (
        <EmptyState
          icon={Users}
          title="Aucun agent dans cette équipe"
          description="Ajoutez des agents depuis la page Équipes."
        />
      ) : (
        <div className="list-agent grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {(teamDetail.agents as AgentSummary[]).map((agent) => (
            <button
              key={agent.id}
              type="button"
              className="item-agent card p-4 flex items-center gap-3 text-left hover:border-[var(--brand)] transition-colors"
              onClick={() => onOpenAgent(agent.id)}
            >
              <div
                className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-semibold"
                style={{ background: 'var(--brand-dim)', color: 'var(--brand)' }}
              >
                {agent.name.charAt(0).toUpperCase()}
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <p className="text-sm font-medium truncate" style={{ color: 'var(--text)' }}>{agent.name}</p>
                  <AgentStatusBadge agentId={agent.id} />
                </div>
                {agent.role && (
                  <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--muted)' }}>{agent.role.name}</p>
                )}
                {!agent.isActive && (
                  <span className="badge-gray text-xs mt-1 inline-block">Inactif</span>
                )}
              </div>
              <Zap
                className="w-4 h-4 flex-shrink-0"
                style={{ color: agent.isActive ? 'var(--brand)' : 'var(--muted)', opacity: agent.isActive ? 1 : 0.3 }}
              />
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
