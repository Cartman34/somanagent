// ─────────────────────────────────────────────────────────────────────────────
// Types partagés SoManAgent
// ─────────────────────────────────────────────────────────────────────────────

export interface Project {
  id: string
  name: string
  description: string | null
  modules: Module[]
  createdAt: string
  updatedAt: string
}

export interface Module {
  id: string
  projectId: string
  name: string
  description: string | null
  techStack: string | null
  repositoryUrl: string | null
  repositoryBranch: string | null
  status: 'active' | 'archived' | 'paused'
  createdAt: string
  updatedAt: string
}

export interface Team {
  id: string
  name: string
  description: string | null
  roles: Role[]
  createdAt: string
  updatedAt: string
}

export interface Role {
  id: string
  teamId: string
  name: string
  description: string | null
  skillSlug: string
}

export interface Agent {
  id: string
  name: string
  connectorName: string
  config: AgentConfig
  createdAt: string
  updatedAt: string
}

export interface AgentConfig {
  model: string
  max_tokens: number
  temperature: number
  extra?: Record<string, unknown>
}

export interface Skill {
  id: string
  slug: string
  name: string
  description: string | null
  content: string
  metadata: Record<string, unknown>
  source: 'imported' | 'custom'
  originRef: string | null
  localPath: string
  createdAt: string
  updatedAt: string
}

export interface Workflow {
  id: string
  name: string
  description: string | null
  trigger: 'manual' | 'vcs_event' | 'scheduled'
  steps: WorkflowStep[]
  isDryRun: boolean
  createdAt: string
  updatedAt: string
}

export interface WorkflowStep {
  stepKey: string
  name: string
  roleId: string
  skillSlug: string
  outputKey: string
  inputSource: 'previous_step' | 'vcs' | 'manual' | 'context'
  dependsOn: string | null
  condition: string | null
}

export interface AuditLog {
  id: string
  action: string
  entityType: string
  entityId: string | null
  context: Record<string, unknown>
  result: string | null
  errorMessage: string | null
  occurredAt: string
}

// ─── Réponses API paginées ────────────────────────────────────────────────────
export interface PaginatedResponse<T> {
  data: T[]
  total: number
  page: number
  perPage: number
}
