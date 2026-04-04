/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useQuery, useQueryClient } from '@tanstack/react-query'
import { FolderKanban, Users, Bot, BookOpen, CheckCircle, XCircle, Activity } from 'lucide-react'
import { Link } from 'react-router-dom'
import { projectsApi } from '@/api/projects'
import { teamsApi } from '@/api/teams'
import { agentsApi } from '@/api/agents'
import { skillsApi } from '@/api/skills'
import { healthApi } from '@/api/health'
import { useTranslation } from '@/hooks/useTranslation'
import { PageSpinner } from '@/components/ui/Spinner'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'

const DASHBOARD_TRANSLATION_KEYS = [
  'dashboard.title',
  'dashboard.description',
  'dashboard.stats.projects',
  'dashboard.stats.teams',
  'dashboard.stats.agents',
  'dashboard.stats.agents_active',
  'dashboard.stats.skills',
  'dashboard.system_state',
  'dashboard.inaccessible',
  'dashboard.operational',
  'dashboard.unavailable',
  'dashboard.claude_cli_auth',
  'dashboard.connected_via',
  'dashboard.not_connected',
  'dashboard.quick_start.title',
  'dashboard.quick_start.create_team',
  'dashboard.quick_start.and_add_roles',
  'dashboard.quick_start.import_skills',
  'dashboard.quick_start.and_associate_roles',
  'dashboard.quick_start.configure_agents',
  'dashboard.quick_start.and_associate_agent_roles',
  'dashboard.quick_start.create_project',
  'dashboard.quick_start.and_add_modules',
  'dashboard.quick_start.define_workflows',
  'dashboard.quick_start.and_orchestrate_agents',
  'dashboard.loading',
  'common.action.refresh',
] as const

interface StatCardProps {
  label: string
  value: number | string
  icon: React.ElementType
  to: string
  color: string
}

function StatCard({ label, value, icon: Icon, to, color }: StatCardProps) {
  return (
    <Link to={to} className="card p-6 flex items-center gap-4 hover:shadow-md transition-shadow">
      <div className={`w-12 h-12 rounded-lg flex items-center justify-center flex-shrink-0 ${color}`}>
        <Icon className="w-6 h-6 text-white" />
      </div>
      <div>
        <p className="text-2xl font-bold" style={{ color: 'var(--text)' }}>{value}</p>
        <p className="text-sm" style={{ color: 'var(--muted)' }}>{label}</p>
      </div>
    </Link>
  )
}

/**
 * Dashboard page — overview of projects and key metrics.
 */
export default function DashboardPage() {
  const qc = useQueryClient()
  const { t } = useTranslation(DASHBOARD_TRANSLATION_KEYS)

  const { data: projects, isLoading: loadingProjects, isFetching: fetchingProjects } = useQuery({
    queryKey: ['projects'],
    queryFn: projectsApi.list,
  })

  const { data: teams, isLoading: loadingTeams, isFetching: fetchingTeams } = useQuery({
    queryKey: ['teams'],
    queryFn: teamsApi.list,
  })

  const { data: agents, isLoading: loadingAgents, isFetching: fetchingAgents } = useQuery({
    queryKey: ['agents'],
    queryFn: agentsApi.list,
  })

  const { data: skills, isLoading: loadingSkills, isFetching: fetchingSkills } = useQuery({
    queryKey: ['skills'],
    queryFn: skillsApi.list,
  })

  const { data: health } = useQuery({
    queryKey: ['health'],
    queryFn: healthApi.check,
    retry: false,
  })

  const { data: connectors } = useQuery({
    queryKey: ['health', 'connectors'],
    queryFn: healthApi.connectors,
    retry: false,
  })

  const { data: claudeCliAuth } = useQuery({
    queryKey: ['health', 'claude-cli-auth'],
    queryFn: healthApi.claudeCliAuth,
    retry: false,
  })

  const loading = loadingProjects || loadingTeams || loadingAgents || loadingSkills
  const isFetching = fetchingProjects || fetchingTeams || fetchingAgents || fetchingSkills

  if (loading) return <PageSpinner />

  const activeAgents = agents?.filter((a) => a.isActive).length ?? 0

  return (
    <>
    <PageHeader title={t('dashboard.title')} description={t('dashboard.description')}
      onRefresh={() => {
        qc.invalidateQueries({ queryKey: ['projects'] })
        qc.invalidateQueries({ queryKey: ['teams'] })
        qc.invalidateQueries({ queryKey: ['agents'] })
        qc.invalidateQueries({ queryKey: ['skills'] })
        qc.invalidateQueries({ queryKey: ['health'] })
      }}
      refreshTitle={t('common.action.refresh')} />

    <div className="relative">
      <ContentLoadingOverlay isLoading={isFetching && !loading} label={t('dashboard.loading')} />

      <div className="space-y-8">
      {/* Stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label={t('dashboard.stats.projects')}
          value={projects?.length ?? 0}
          icon={FolderKanban}
          to="/projects"
          color="bg-blue-500"
        />
        <StatCard
          label={t('dashboard.stats.teams')}
          value={teams?.length ?? 0}
          icon={Users}
          to="/teams"
          color="bg-indigo-500"
        />
        <StatCard
          label={`${t('dashboard.stats.agents')} (${activeAgents} ${t('dashboard.stats.agents_active')}${activeAgents !== 1 ? 's' : ''})`}
          value={agents?.length ?? 0}
          icon={Bot}
          to="/agents"
          color="bg-violet-500"
        />
        <StatCard
          label={t('dashboard.stats.skills')}
          value={skills?.length ?? 0}
          icon={BookOpen}
          to="/skills"
          color="bg-emerald-500"
        />
      </div>

      {/* Health */}
      <div className="card p-6">
        <div className="flex items-center gap-2 mb-4">
          <Activity className="w-5 h-5" style={{ color: 'var(--muted)' }} />
          <h2 className="text-base font-semibold" style={{ color: 'var(--text)' }}>
            {t('dashboard.system_state')}
          </h2>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          {/* API */}
          <div
            className="flex items-center gap-3 p-3 rounded-lg"
            style={{ background: 'var(--surface2)' }}
          >
            {health?.status === 'ok' ? (
              <CheckCircle className="w-5 h-5 text-green-500 flex-shrink-0" />
            ) : (
              <XCircle className="w-5 h-5 text-red-500 flex-shrink-0" />
            )}
            <div>
              <p className="text-sm font-medium" style={{ color: 'var(--text)' }}>
                Backend API
              </p>
              <p className="text-xs" style={{ color: 'var(--muted)' }}>
                {health ? `v${health.version} — ${health.status}` : t('dashboard.inaccessible')}
              </p>
            </div>
          </div>

          {/* Connectors */}
          {connectors
            ? Object.entries(connectors.connectors).map(([name, ok]) => (
                <div
                  key={name}
                  className="flex items-center gap-3 p-3 rounded-lg"
                  style={{ background: 'var(--surface2)' }}
                >
                  {ok ? (
                    <CheckCircle className="w-5 h-5 text-green-500 flex-shrink-0" />
                  ) : (
                    <XCircle className="w-5 h-5 text-red-500 flex-shrink-0" />
                  )}
                  <div>
                    <p className="text-sm font-medium" style={{ color: 'var(--text)' }}>
                      {name}
                    </p>
                    <p className="text-xs" style={{ color: 'var(--muted)' }}>
                      {ok ? t('dashboard.operational') : t('dashboard.unavailable')}
                    </p>
                  </div>
                </div>
              ))
            : null}

          <div
            className="flex items-center gap-3 p-3 rounded-lg"
            style={{ background: 'var(--surface2)' }}
          >
            {claudeCliAuth?.loggedIn ? (
              <CheckCircle className="w-5 h-5 text-green-500 flex-shrink-0" />
            ) : (
              <XCircle className="w-5 h-5 text-red-500 flex-shrink-0" />
            )}
            <div>
              <p className="text-sm font-medium" style={{ color: 'var(--text)' }}>
                {t('dashboard.claude_cli_auth')}
              </p>
              <p className="text-xs" style={{ color: 'var(--muted)' }}>
                {claudeCliAuth?.loggedIn
                  ? `${t('dashboard.connected_via')} ${claudeCliAuth.authMethod ?? 'unknown'}`
                  : t('dashboard.not_connected')}
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* Quick links */}
      <div className="card p-6">
        <h2 className="text-base font-semibold mb-4" style={{ color: 'var(--text)' }}>
          {t('dashboard.quick_start.title')}
        </h2>
        <ol className="space-y-2 text-sm list-decimal list-inside" style={{ color: 'var(--muted)' }}>
          <li>
            <Link to="/teams" className="hover:underline" style={{ color: 'var(--brand)' }}>
              {t('dashboard.quick_start.create_team')}
            </Link>{' '}
            {t('dashboard.quick_start.and_add_roles')}
          </li>
          <li>
            <Link to="/skills" className="hover:underline" style={{ color: 'var(--brand)' }}>
              {t('dashboard.quick_start.import_skills')}
            </Link>{' '}
            {t('dashboard.quick_start.and_associate_roles')}
          </li>
          <li>
            <Link to="/agents" className="hover:underline" style={{ color: 'var(--brand)' }}>
              {t('dashboard.quick_start.configure_agents')}
            </Link>{' '}
            {t('dashboard.quick_start.and_associate_agent_roles')}
          </li>
          <li>
            <Link to="/projects" className="hover:underline" style={{ color: 'var(--brand)' }}>
              {t('dashboard.quick_start.create_project')}
            </Link>{' '}
            {t('dashboard.quick_start.and_add_modules')}
          </li>
          <li>
            <Link to="/workflows" className="hover:underline" style={{ color: 'var(--brand)' }}>
              {t('dashboard.quick_start.define_workflows')}
            </Link>{' '}
            {t('dashboard.quick_start.and_orchestrate_agents')}
          </li>
        </ol>
      </div>
    </div>
    </div>
    </>
  )
}
