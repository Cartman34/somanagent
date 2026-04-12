/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useEffect, useState } from 'react'
import { AlertCircle } from 'lucide-react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { projectsApi } from '@/api/projects'
import { rolesApi } from '@/api/roles'
import { teamsApi } from '@/api/teams'
import type { Project } from '@/types'
import { useTranslation } from '@/hooks/useTranslation'
import { useToast } from '@/hooks/useToast'

const PROJECT_GENERAL_TAB_TRANSLATION_KEYS = [
  'common.action.save',
  'common.action.saving',
  'project.detail.general.assigned_team_title',
  'project.detail.general.default_ticket_role_title',
  'project.detail.general.description_label',
  'project.detail.general.dispatch_mode_saved_help',
  'project.detail.general.dispatch_mode_title',
  'project.detail.general.information_title',
  'project.detail.general.save_default_ticket_role_error',
  'project.detail.general.save_dispatch_mode_error',
  'project.detail.general.save_team_error',
  'project.form.default_ticket_role_hint',
  'project.form.default_ticket_role_label',
  'project.form.dispatch_mode_auto',
  'project.form.dispatch_mode_hint',
  'project.form.dispatch_mode_label',
  'project.form.dispatch_mode_manual',
  'project.form.name_label',
  'project.form.no_role_option',
  'project.form.no_team_option',
  'project.form.team_hint',
  'project.form.team_label',
  'toast.saved',
] as const

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
  const { t } = useTranslation(PROJECT_GENERAL_TAB_TRANSLATION_KEYS)
  const { toast } = useToast()
  const [selectedTeamId, setSelectedTeamId] = useState<string | null>(null)
  const [teamSaveError, setTeamSaveError]   = useState<string | null>(null)
  const [selectedDispatchMode, setSelectedDispatchMode] = useState(project.dispatchMode)
  const [dispatchModeSaveError, setDispatchModeSaveError] = useState<string | null>(null)
  const [selectedDefaultTicketRoleId, setSelectedDefaultTicketRoleId] = useState<string | null>(null)
  const [defaultTicketRoleSaveError, setDefaultTicketRoleSaveError] = useState<string | null>(null)

  useEffect(() => {
    setSelectedDispatchMode(project.dispatchMode)
  }, [project.dispatchMode])

  const { data: teamsList = [] } = useQuery({
    queryKey: ['teams'],
    queryFn:  teamsApi.list,
  })

  const { data: rolesList = [] } = useQuery({
    queryKey: ['roles'],
    queryFn:  rolesApi.list,
  })

  const updateTeamMutation = useMutation({
    mutationFn: (teamId: string | null) => projectsApi.update(projectId, {
      name:        project.name,
      description: project.description ?? undefined,
      workflowId:  project.workflow?.id ?? null,
      dispatchMode: selectedDispatchMode,
      teamId:      teamId,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['projects', projectId] })
      setTeamSaveError(null)
      toast.success(t('toast.saved'), 'project-team')
    },
    onError: () => setTeamSaveError(t('project.detail.general.save_team_error')),
  })

  const updateDispatchModeMutation = useMutation({
    mutationFn: (dispatchMode: Project['dispatchMode']) => projectsApi.update(projectId, {
      name: project.name,
      description: project.description ?? undefined,
      workflowId: project.workflow?.id ?? null,
      teamId: project.team?.id ?? null,
      dispatchMode,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['projects', projectId] })
      qc.invalidateQueries({ queryKey: ['tickets', projectId] })
      setDispatchModeSaveError(null)
      toast.success(t('toast.saved'), 'project-dispatch')
    },
    onError: () => setDispatchModeSaveError(t('project.detail.general.save_dispatch_mode_error')),
  })

  const updateDefaultTicketRoleMutation = useMutation({
    mutationFn: (defaultTicketRoleId: string | null) => projectsApi.update(projectId, {
      name:               project.name,
      description:        project.description ?? undefined,
      workflowId:         project.workflow?.id ?? null,
      teamId:             project.team?.id ?? null,
      dispatchMode:       selectedDispatchMode,
      defaultTicketRoleId,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['projects', projectId] })
      setDefaultTicketRoleSaveError(null)
      toast.success(t('toast.saved'), 'project-default-ticket-role')
    },
    onError: () => setDefaultTicketRoleSaveError(t('project.detail.general.save_default_ticket_role_error')),
  })

  return (
    <div className="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]">
      <div className="card p-5 space-y-4 xl:min-h-full">
        <h3 className="text-sm font-semibold" style={{ color: 'var(--text)' }}>{t('project.detail.general.information_title')}</h3>
        <div>
          <p className="text-xs mb-0.5" style={{ color: 'var(--muted)' }}>{t('project.form.name_label')}</p>
          <p className="text-sm font-medium" style={{ color: 'var(--text)' }}>{project.name}</p>
        </div>
        {project.description && (
          <div>
            <p className="text-xs mb-0.5" style={{ color: 'var(--muted)' }}>{t('project.detail.general.description_label')}</p>
            <p className="text-sm" style={{ color: 'var(--text)' }}>{project.description}</p>
          </div>
        )}
      </div>

      <div className="grid gap-6">
        <div className="card p-5 space-y-4">
          <h3 className="text-sm font-semibold" style={{ color: 'var(--text)' }}>{t('project.detail.general.assigned_team_title')}</h3>
          <p className="text-xs" style={{ color: 'var(--muted)' }}>
            {t('project.form.team_hint')}
          </p>
          <div>
            <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{t('project.form.team_label')}</label>
            <select
              className="input"
              value={selectedTeamId ?? (project.team?.id ?? '')}
              onChange={(e) => setSelectedTeamId(e.target.value)}
            >
              <option value="">{t('project.form.no_team_option')}</option>
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
            {updateTeamMutation.isPending ? t('common.action.saving') : t('common.action.save')}
          </button>
        </div>

        <div className="card p-5 space-y-4">
          <h3 className="text-sm font-semibold" style={{ color: 'var(--text)' }}>{t('project.detail.general.default_ticket_role_title')}</h3>
          <p className="text-xs" style={{ color: 'var(--muted)' }}>
            {t('project.form.default_ticket_role_hint')}
          </p>
          <div>
            <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{t('project.form.default_ticket_role_label')}</label>
            <select
              className="input"
              value={selectedDefaultTicketRoleId ?? (project.defaultTicketRole?.id ?? '')}
              onChange={(e) => setSelectedDefaultTicketRoleId(e.target.value)}
            >
              <option value="">{t('project.form.no_role_option')}</option>
              {rolesList.map((r) => (
                <option key={r.id} value={r.id}>{r.name}</option>
              ))}
            </select>
          </div>
          {defaultTicketRoleSaveError && (
            <p className="text-sm text-red-600 flex items-center gap-1">
              <AlertCircle className="w-4 h-4" />{defaultTicketRoleSaveError}
            </p>
          )}
          <button
            className="btn-primary"
            disabled={updateDefaultTicketRoleMutation.isPending}
            onClick={() => {
              const effectiveId = selectedDefaultTicketRoleId ?? (project.defaultTicketRole?.id ?? '')
              updateDefaultTicketRoleMutation.mutate(effectiveId || null)
            }}
          >
            {updateDefaultTicketRoleMutation.isPending ? t('common.action.saving') : t('common.action.save')}
          </button>
        </div>

        <div className="card p-5 space-y-4">
          <h3 className="text-sm font-semibold" style={{ color: 'var(--text)' }}>{t('project.detail.general.dispatch_mode_title')}</h3>
          <p className="text-xs" style={{ color: 'var(--muted)' }}>
            {t('project.detail.general.dispatch_mode_saved_help')}
          </p>
          <div>
            <label className="block text-sm font-medium mb-1" style={{ color: 'var(--text)' }}>{t('project.form.dispatch_mode_label')}</label>
            <select
              className="input"
              value={selectedDispatchMode}
              onChange={(event) => setSelectedDispatchMode(event.target.value as Project['dispatchMode'])}
            >
              <option value="auto">{t('project.form.dispatch_mode_auto')}</option>
              <option value="manual">{t('project.form.dispatch_mode_manual')}</option>
            </select>
            <p className="mt-2 text-xs" style={{ color: 'var(--muted)' }}>
              {t('project.form.dispatch_mode_hint')}
            </p>
          </div>
          {dispatchModeSaveError && (
            <p className="text-sm text-red-600 flex items-center gap-1">
              <AlertCircle className="w-4 h-4" />{dispatchModeSaveError}
            </p>
          )}
          <button
            className="btn-primary"
            disabled={updateDispatchModeMutation.isPending}
            onClick={() => updateDispatchModeMutation.mutate(selectedDispatchMode)}
          >
            {updateDispatchModeMutation.isPending ? t('common.action.saving') : t('common.action.save')}
          </button>
        </div>
      </div>
    </div>
  )
}
