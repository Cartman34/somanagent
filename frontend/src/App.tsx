import { Routes, Route, Navigate } from 'react-router-dom'
import Layout from '@/components/layout/Layout'
import DashboardPage from '@/pages/DashboardPage'
import ProjectsPage from '@/pages/ProjectsPage'
import TeamsPage from '@/pages/TeamsPage'
import AgentsPage from '@/pages/AgentsPage'
import RolesPage from '@/pages/RolesPage'
import SkillsPage from '@/pages/SkillsPage'
import WorkflowsPage from '@/pages/WorkflowsPage'
import FeaturesPage from '@/pages/FeaturesPage'
import ChatPage from '@/pages/ChatPage'
import TokensPage from '@/pages/TokensPage'
import AuditPage from '@/pages/AuditPage'
import LogsPage from '@/pages/LogsPage'

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Layout />}>
        <Route index element={<Navigate to="/dashboard" replace />} />
        <Route path="dashboard"    element={<DashboardPage />} />
        <Route path="projects/*"   element={<ProjectsPage />} />
        <Route path="features/*"   element={<FeaturesPage />} />
        <Route path="teams/*"      element={<TeamsPage />} />
        <Route path="agents/*"     element={<AgentsPage />} />
        <Route path="roles/*"      element={<RolesPage />} />
        <Route path="skills/*"     element={<SkillsPage />} />
        <Route path="workflows/*"  element={<WorkflowsPage />} />
        <Route path="chat/*"       element={<ChatPage />} />
        <Route path="tokens/*"     element={<TokensPage />} />
        <Route path="audit"        element={<AuditPage />} />
        <Route path="logs"         element={<LogsPage />} />
        {/* Legacy French URL redirects */}
        <Route path="tableau-de-bord" element={<Navigate to="/dashboard" replace />} />
        <Route path="projets/*"       element={<Navigate to="/projects" replace />} />
        <Route path="tasks/*"         element={<Navigate to="/projects" replace />} />
        <Route path="taches/*"        element={<Navigate to="/projects" replace />} />
        <Route path="equipes/*"       element={<Navigate to="/teams" replace />} />
        <Route path="competences/*"   element={<Navigate to="/skills" replace />} />
      </Route>
    </Routes>
  )
}
