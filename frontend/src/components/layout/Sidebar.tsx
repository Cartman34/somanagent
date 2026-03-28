import { NavLink } from 'react-router-dom'
import {
  LayoutDashboard,
  FolderKanban,
  Users,
  Bot,
  UserCog,
  BookOpen,
  GitBranch,
  ScrollText,
  Coins,
  AlertOctagon,
} from 'lucide-react'
import ThemeSwitcher from '@/components/ui/ThemeSwitcher'

const navigation = [
  { to: '/dashboard',  label: 'Tableau de bord', icon: LayoutDashboard },
  { to: '/projects',   label: 'Projets',          icon: FolderKanban },
  { to: '/teams',      label: 'Équipes',          icon: Users },
  { to: '/agents',     label: 'Agents',           icon: Bot },
  { to: '/roles',      label: 'Rôles',            icon: UserCog },
  { to: '/skills',     label: 'Compétences',      icon: BookOpen },
  { to: '/workflows',  label: 'Workflows',        icon: GitBranch },
  { to: '/tokens',     label: 'Tokens',           icon: Coins },
  { to: '/audit',      label: 'Journal d\'audit', icon: ScrollText },
  { to: '/logs',       label: 'Logs',             icon: AlertOctagon },
]

export default function Sidebar() {
  return (
    <aside
      className="w-64 flex-shrink-0 flex flex-col"
      style={{ background: 'var(--sidebar)', borderRight: '1px solid var(--border)' }}
    >
      {/* Logo */}
      <div
        className="h-16 flex items-center px-5"
        style={{ borderBottom: '1px solid var(--border)' }}
      >
        <div className="flex items-center gap-2.5">
          <div
            className="w-8 h-8 flex items-center justify-center flex-shrink-0"
            style={{
              background: 'var(--brand)',
              borderRadius: 'var(--radius)',
              color: 'var(--brand-text)',
            }}
          >
            <Bot className="w-4 h-4" />
          </div>
          <span className="font-bold text-sm" style={{ color: 'var(--text)' }}>
            SoManAgent
          </span>
        </div>
      </div>

      {/* Navigation */}
      <nav className="flex-1 px-2 py-3 overflow-y-auto" style={{ display: 'flex', flexDirection: 'column', gap: '2px' }}>
        {navigation.map(({ to, label, icon: Icon }) => (
          <NavLink
            key={to}
            to={to}
            className="flex items-center gap-3 px-3 py-2 text-sm font-medium transition-colors"
            style={({ isActive }) => ({
              borderRadius: 'var(--radius)',
              background: isActive ? 'var(--brand-dim)' : 'transparent',
              color: isActive ? 'var(--brand)' : 'var(--muted)',
            })}
          >
            {({ isActive }) => (
              <>
                <Icon className="w-4 h-4 flex-shrink-0" style={{ opacity: isActive ? 1 : 0.7 }} />
                {label}
              </>
            )}
          </NavLink>
        ))}
      </nav>

      {/* Footer */}
      <div
        className="px-4 py-3 flex items-center justify-between"
        style={{ borderTop: '1px solid var(--border)' }}
      >
        <span className="text-xs" style={{ color: 'var(--muted)' }}>
          v0.2.0
        </span>
        <ThemeSwitcher />
      </div>
    </aside>
  )
}
