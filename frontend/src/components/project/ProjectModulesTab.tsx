/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { Code2, Globe } from 'lucide-react'
import type { Module } from '@/types'
import EmptyState from '@/components/ui/EmptyState'

/**
 * Modules tab — lists software modules attached to the project.
 *
 * @see projectsApi.get — modules are loaded as part of the project payload
 */
export default function ProjectModulesTab({ modules }: { modules: Module[] }) {
  if (modules.length === 0) {
    return (
      <EmptyState
        icon={Code2}
        title="Aucun module"
        description="Les modules représentent les composants logiciels du projet (API, client mobile, etc.)."
      />
    )
  }

  return (
    <div className="list-module grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {modules.map((mod) => (
        <div key={mod.id} className="item-module card p-4 flex flex-col gap-2">
          <p className="font-medium text-sm" style={{ color: 'var(--text)' }}>{mod.name}</p>
          {mod.description && <p className="text-xs" style={{ color: 'var(--muted)' }}>{mod.description}</p>}
          {mod.stack && <span className="badge-blue self-start">{mod.stack}</span>}
          {mod.repositoryUrl && (
            <a href={mod.repositoryUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs" style={{ color: 'var(--brand)' }}>
              <Globe className="w-3 h-3" /> Dépôt
            </a>
          )}
          <div className="mt-auto">
            <span className={mod.status === 'active' ? 'badge-green' : 'badge-gray'}>
              {mod.status === 'active' ? 'Actif' : 'Archivé'}
            </span>
          </div>
        </div>
      ))}
    </div>
  )
}
