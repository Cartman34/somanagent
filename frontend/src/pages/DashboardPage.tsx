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
      <div className={`w-12 h-12 rounded-lg flex items-center justify-center ${color}`}>
        <Icon className="w-6 h-6 text-white" />
      </div>
      <div>
        <p className="text-2xl font-bold text-gray-900">{value}</p>
        <p className="text-sm text-gray-500">{label}</p>
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
          <Activity className="w-5 h-5 text-gray-500" />
          <h2 className="text-base font-semibold text-gray-900">System health</h2>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          {/* API */}
          <div className="flex items-center gap-3 p-3 rounded-lg bg-gray-50">
            {health?.status === 'ok' ? (
              <CheckCircle className="w-5 h-5 text-green-500 flex-shrink-0" />
            ) : (
              <XCircle className="w-5 h-5 text-red-500 flex-shrink-0" />
            )}
            <div>
              <p className="text-sm font-medium text-gray-900">Backend API</p>
              <p className="text-xs text-gray-500">
                {health ? `v${health.version} — ${health.status}` : 'Unreachable'}
              </p>
            </div>
          </div>

          {/* Connectors */}
          {connectors
            ? Object.entries(connectors.connectors).map(([name, ok]) => (
                <div key={name} className="flex items-center gap-3 p-3 rounded-lg bg-gray-50">
                  {ok ? (
                    <CheckCircle className="w-5 h-5 text-green-500 flex-shrink-0" />
                  ) : (
                    <XCircle className="w-5 h-5 text-red-500 flex-shrink-0" />
                  )}
                  <div>
                    <p className="text-sm font-medium text-gray-900">{name}</p>
                    <p className="text-xs text-gray-500">{ok ? 'Operational' : 'Unavailable'}</p>
                  </div>
                </div>
              ))
            : null}
        </div>
      </div>

      {/* Quick links */}
      <div className="card p-6">
        <h2 className="text-base font-semibold text-gray-900 mb-4">Quick start</h2>
        <ol className="space-y-2 text-sm text-gray-600 list-decimal list-inside">
          <li>
            <Link to="/teams" className="text-brand-600 hover:underline">
              Create a team
            </Link>{' '}
            and add roles to it
          </li>
          <li>
            <Link to="/skills" className="text-brand-600 hover:underline">
              Import or create skills
            </Link>{' '}
            to assign to roles
          </li>
          <li>
            <Link to="/agents" className="text-brand-600 hover:underline">
              Configure agents
            </Link>{' '}
            and link them to roles
          </li>
          <li>
            <Link to="/projects" className="text-brand-600 hover:underline">
              Create a project
            </Link>{' '}
            and add modules (PHP API, Android, etc.)
          </li>
          <li>
            <Link to="/workflows" className="text-brand-600 hover:underline">
              Define workflows
            </Link>{' '}
            to orchestrate your agents
          </li>
        </ol>
      </div>
    </div>
  )
}
