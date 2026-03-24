import { Routes, Route, Navigate } from 'react-router-dom'
import Layout from '@/components/layout/Layout'
import DashboardPage from '@/pages/DashboardPage'
import ProjectsPage from '@/pages/ProjectsPage'
import TeamsPage from '@/pages/TeamsPage'
import AgentsPage from '@/pages/AgentsPage'
import SkillsPage from '@/pages/SkillsPage'
import WorkflowsPage from '@/pages/WorkflowsPage'
import AuditPage from '@/pages/AuditPage'

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Layout />}>
        <Route index element={<Navigate to="/dashboard" replace />} />
        <Route path="dashboard"  element={<DashboardPage />} />
        <Route path="projects/*" element={<ProjectsPage />} />
        <Route path="teams/*"    element={<TeamsPage />} />
        <Route path="agents/*"   element={<AgentsPage />} />
        <Route path="skills/*"   element={<SkillsPage />} />
        <Route path="workflows/*" element={<WorkflowsPage />} />
        <Route path="audit"      element={<AuditPage />} />
      </Route>
    </Routes>
  )
}
