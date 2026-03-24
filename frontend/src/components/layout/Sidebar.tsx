import { NavLink } from 'react-router-dom'
import {
  LayoutDashboard,
  FolderKanban,
  Users,
  Bot,
  BookOpen,
  GitBranch,
  ScrollText,
} from 'lucide-react'
import clsx from 'clsx'

const navigation = [
  { to: '/dashboard',  label: 'Tableau de bord', icon: LayoutDashboard },
  { to: '/projects',   label: 'Projets',          icon: FolderKanban },
  { to: '/teams',      label: 'Équipes',           icon: Users },
  { to: '/agents',     label: 'Agents',            icon: Bot },
  { to: '/skills',     label: 'Skills',            icon: BookOpen },
  { to: '/workflows',  label: 'Workflows',         icon: GitBranch },
  { to: '/audit',      label: 'Journal d\'audit',  icon: ScrollText },
]

export default function Sidebar() {
  return (
    <aside className="w-64 flex-shrink-0 bg-white border-r border-gray-200 flex flex-col">
      {/* Logo */}
      <div className="h-16 flex items-center px-6 border-b border-gray-200">
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 bg-brand-600 rounded-lg flex items-center justify-center">
            <Bot className="w-5 h-5 text-white" />
          </div>
          <span className="font-bold text-gray-900">SoManAgent</span>
        </div>
      </div>

      {/* Navigation */}
      <nav className="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
        {navigation.map(({ to, label, icon: Icon }) => (
          <NavLink
            key={to}
            to={to}
            className={({ isActive }) =>
              clsx(
                'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
                isActive
                  ? 'bg-brand-50 text-brand-700'
                  : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
              )
            }
          >
            <Icon className="w-5 h-5 flex-shrink-0" />
            {label}
          </NavLink>
        ))}
      </nav>

      {/* Version */}
      <div className="px-6 py-4 border-t border-gray-200">
        <p className="text-xs text-gray-400">Version 0.1.0</p>
      </div>
    </aside>
  )
}
