import { useQuery } from '@tanstack/react-query'
import { FolderKanban, Users, Bot, BookOpen, CheckCircle, XCircle, Activity } from 'lucide-react'
import { Link } from 'react-router-dom'
import { projectsApi } from '@/api/projects'
import { teamsApi } from '@/api/teams'
import { agentsApi } from '@/api/agents'
import { skillsApi } from '@/api/skills'
import { healthApi } from '@/api/health'
import { PageSpinner } from '@/components/ui/Spinner'

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

export default function DashboardPage() {
  const { data: projects, isLoading: loadingProjects } = useQuery({
    queryKey: ['projects'],
    queryFn: projectsApi.list,
  })

  const { data: teams, isLoading: loadingTeams } = useQuery({
    queryKey: ['teams'],
    queryFn: teamsApi.list,
  })

  const { data: agents, isLoading: loadingAgents } = useQuery({
    queryKey: ['agents'],
    queryFn: agentsApi.list,
  })

  const { data: skills, isLoading: loadingSkills } = useQuery({
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

  const loading = loadingProjects || loadingTeams || loadingAgents || loadingSkills

  if (loading) return <PageSpinner />

  const activeAgents = agents?.filter((a) => a.isActive).length ?? 0

  return (
    <div className="space-y-8">
      {/* Stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="Projects"
          value={projects?.length ?? 0}
          icon={FolderKanban}
          to="/projects"
          color="bg-blue-500"
        />
        <StatCard
          label="Teams"
          value={teams?.length ?? 0}
          icon={Users}
          to="/teams"
          color="bg-indigo-500"
        />
        <StatCard
          label={`Agents (${activeAgents} active)`}
          value={agents?.length ?? 0}
          icon={Bot}
          to="/agents"
          color="bg-violet-500"
        />
        <StatCard
          label="Skills"
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
            System health
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
                {health ? `v${health.version} — ${health.status}` : 'Unreachable'}
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
                      {ok ? 'Operational' : 'Unavailable'}
                    </p>
                  </div>
                </div>
              ))
            : null}
        </div>
      </div>

      {/* Quick links */}
      <div className="card p-6">
        <h2 className="text-base font-semibold mb-4" style={{ color: 'var(--text)' }}>
          Quick start
        </h2>
        <ol className="space-y-2 text-sm list-decimal list-inside" style={{ color: 'var(--muted)' }}>
          <li>
            <Link to="/teams" className="hover:underline" style={{ color: 'var(--brand)' }}>
              Create a team
            </Link>{' '}
            and add roles to it
          </li>
          <li>
            <Link to="/skills" className="hover:underline" style={{ color: 'var(--brand)' }}>
              Import or create skills
            </Link>{' '}
            to assign to roles
          </li>
          <li>
            <Link to="/agents" className="hover:underline" style={{ color: 'var(--brand)' }}>
              Configure agents
            </Link>{' '}
            and link them to roles
          </li>
          <li>
            <Link to="/projects" className="hover:underline" style={{ color: 'var(--brand)' }}>
              Create a project
            </Link>{' '}
            and add modules (PHP API, Android, etc.)
          </li>
          <li>
            <Link to="/workflows" className="hover:underline" style={{ color: 'var(--brand)' }}>
              Define workflows
            </Link>{' '}
            to orchestrate your agents
          </li>
        </ol>
      </div>
    </div>
  )
}
