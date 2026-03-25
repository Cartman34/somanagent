import { useLocation } from 'react-router-dom'

const pageTitles: Record<string, string> = {
  '/dashboard':  'Dashboard',
  '/projects':   'Projects',
  '/teams':      'Teams',
  '/agents':     'Agents',
  '/skills':     'Skills',
  '/workflows':  'Workflows',
  '/audit':      'Audit log',
}

export default function TopBar() {
  const location = useLocation()
  const segment = '/' + location.pathname.split('/')[1]
  const title = pageTitles[segment] ?? 'SoManAgent'

  return (
    <header className="h-16 bg-white border-b border-gray-200 flex items-center px-6">
      <h1 className="text-xl font-semibold text-gray-900">{title}</h1>
    </header>
  )
}
