/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState } from 'react'
import { AlertCircle } from 'lucide-react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { projectsApi } from '@/api/projects'
import { teamsApi } from '@/api/teams'
import type { Project } from '@/types'

/**
 * General tab — project information and team assignment.
 *
 * @see projectsApi.update
 * @see teamsApi.list
 */
export default function ProjectGeneralTab({ project, projectId }: {
  project: Project
  projectId: string
}) {
  const qc = useQueryClient()
  const [selectedTeamId, setSelectedTeamId] = useState<string | null>(null)
  const [teamSaveError, setTeamSaveError]   = useState<string | null>(null)

  const { data: teamsList = [] } = useQuery({
    queryKey: ['teams'],
    queryFn:  teamsApi.list,
  })

  const updateTeamMutation = useMutation({
    mutationFn: (teamId: string | null) => projectsApi.update(projectId, {
      name:        project.name,
      description: project.description ?? undefined,
      teamId:      teamId,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['projects', projectId] })
      setTeamSaveError(null)
    },
    onError: () => setTeamSaveError('Impossible de sauvegarder l\'équipe.'),
  })

  return (
    <div className="max-w-lg space-y-6">
      <div className="card p-5 space-y-4">
        <h3 className="text-sm font-semibold" style={{ color: 'var(--text)' }}>Informations</h3>
        <div>
          <p className="text-xs mb-0.5" style={{ color: 'var(--muted)' }}>Nom</p>
          <p className="text-sm font-medium" style={{ color: 'var(--text)' }}>{project.name}</p>
        </div>
        {project.description && (
          <div>
            <p className="text-xs mb-0.5" style={{ color: 'var(--muted)' }}>Description</p>
            <p className="text-sm" style={{ color: 'var(--text)' }}>{project.description}</p>
          </div>
        )}
      </div>

      <div className="card p-5 space-y-4">
        <h3 className="text-sm font-semibold" style={{ color: 'var(--text)' }}>Équipe assignée</h3>
        <p className="text-xs" style={{ color: 'var(--muted)' }}>
          L'équipe détermine les agents disponibles pour l'exécution des stories.
        </p>
        <div>
          <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>Équipe</label>
          <select
            className="input"
            value={selectedTeamId ?? (project.team?.id ?? '')}
            onChange={(e) => setSelectedTeamId(e.target.value)}
          >
            <option value="">— Aucune équipe —</option>
            {teamsList.map((t) => (
              <option key={t.id} value={t.id}>{t.name}</option>
            ))}
          </select>
        </div>
        {teamSaveError && (
          <p className="text-sm text-red-600 flex items-center gap-1">
            <AlertCircle className="w-4 h-4" />{teamSaveError}
          </p>
        )}
        <button
          className="btn-primary"
          disabled={updateTeamMutation.isPending}
          onClick={() => {
            const effectiveId = selectedTeamId ?? (project.team?.id ?? '')
            updateTeamMutation.mutate(effectiveId || null)
          }}
        >
          {updateTeamMutation.isPending ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </div>
    </div>
  )
}
