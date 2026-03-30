/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

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
    <header
      className="h-14 flex items-center px-6"
      style={{ background: 'var(--surface)', borderBottom: '1px solid var(--border)' }}
    >
      <h1 className="text-base font-semibold" style={{ color: 'var(--text)' }}>
        {title}
      </h1>
    </header>
  )
}
