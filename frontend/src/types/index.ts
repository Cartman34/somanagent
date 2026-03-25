// ─────────────────────────────────────────────────────────────────────────────
// Types partagés SoManAgent — alignés sur les réponses de l'API PHP
// ─────────────────────────────────────────────────────────────────────────────

export interface Project {
  id: string
  name: string
  description: string | null
  modules: number | Module[]   // compteur sur /api/projects, tableau sur /api/projects/{id}
  createdAt: string
  updatedAt: string
}

export interface Module {
  id: string
  name: string
  description: string | null
  repositoryUrl: string | null
  stack: string | null
  status: 'active' | 'archived'
}

export interface Team {
  id: string
  name: string
  description: string | null
  roles: number | Role[]
  createdAt: string
  updatedAt: string
}

export interface Role {
  id: string
  name: string
  description: string | null
  skillSlug: string | null
}

export interface AgentConfig {
  model: string
  max_tokens: number
  temperature: number
  timeout: number
  extra?: Record<string, unknown>
}

export interface Agent {
  id: string
  name: string
  description: string | null
  connector: 'claude_api' | 'claude_cli'
  connectorLabel: string
  isActive: boolean
  role: { id: string; name: string } | null
  config: AgentConfig
  createdAt: string
  updatedAt: string
}

export interface Skill {
  id: string
  slug: string
  name: string
  description: string | null
  source: 'imported' | 'custom'
  sourceLabel: string
  originalSource: string | null
  content?: string       // présent uniquement sur GET /api/skills/{id}
  filePath?: string
  createdAt: string
  updatedAt: string
}

export interface WorkflowStep {
  id: string
  stepOrder: number
  name: string
  roleSlug: string | null
  skillSlug: string | null
  inputConfig: Record<string, unknown>
  outputKey: string
  condition: string | null
  status: 'pending' | 'running' | 'done' | 'error' | 'skipped'
  lastOutput: string | null
}

export interface Workflow {
  id: string
  name: string
  description: string | null
  trigger: 'manual' | 'vcs_event' | 'scheduled'
  team: { id: string; name: string } | null
  isActive: boolean
  steps: WorkflowStep[] | number  // count in list, full array in detail
  createdAt: string
  updatedAt: string
}

export interface AuditLog {
  id: string
  action: string
  entityType: string
  entityId: string | null
  data: Record<string, unknown> | null
  createdAt: string
}

export interface ConnectorHealth {
  status: 'ok' | 'degraded'
  connectors: Record<string, boolean>
}
