// ─────────────────────────────────────────────────────────────────────────────
// Types partagés SoManAgent — alignés sur les réponses de l'API PHP
// ─────────────────────────────────────────────────────────────────────────────

export interface Project {
  id: string
  name: string
  description: string | null
  repositoryUrl: string | null
  team: { id: string; name: string } | null
  modules: number | Module[]
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
  agentCount: number
  agents?: AgentSummary[]
  createdAt: string
  updatedAt: string
}

export interface Role {
  id: string
  slug: string
  name: string
  description: string | null
  skills?: SkillSummary[]
}

export interface SkillSummary {
  id: string
  name: string
  slug?: string
  description?: string | null
  content?: string
  filePath?: string
}

export interface AgentSummary {
  id: string
  name: string
  isActive: boolean
  role: { id: string; name: string; slug: string } | null
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
  role: { id: string; name: string; slug: string } | null
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
  content?: string
  filePath?: string
  createdAt: string
  updatedAt: string
}

export interface Feature {
  id: string
  name: string
  description: string | null
  status: 'open' | 'in_progress' | 'closed'
  project?: { id: string; name: string }
  createdAt: string
  updatedAt: string
}

export type TaskType     = 'user_story' | 'bug' | 'task'
export type TaskStatus   = 'backlog' | 'todo' | 'in_progress' | 'review' | 'done' | 'cancelled'
export type TaskPriority = 'low' | 'medium' | 'high' | 'critical'
export type StoryStatus  = 'new' | 'ready' | 'approved' | 'planning' | 'graphic_design' | 'development' | 'code_review' | 'done'

export interface Task {
  id: string
  title: string
  description: string | null
  type: TaskType
  status: TaskStatus
  storyStatus: StoryStatus | null
  storyStatusAllowedTransitions: StoryStatus[]
  priority: TaskPriority
  progress: number
  branchName: string | null
  feature: { id: string; name: string } | null
  parent: { id: string; title: string } | null
  assignedAgent: { id: string; name: string } | null
  assignedRole: { id: string; name: string; slug: string } | null
  addedBy: { id: string; name: string } | null
  children?: Task[]
  logs?: TaskLog[]
  tokenUsage?: TokenUsageEntry[]
  createdAt: string
  updatedAt: string
}

export interface TaskLog {
  id: string
  action: string
  kind: 'event' | 'comment' | string
  authorType: 'agent' | 'user' | 'system' | string | null
  authorName: string | null
  requiresAnswer: boolean
  replyToLogId: string | null
  metadata: Record<string, unknown> | null
  content: string | null
  createdAt: string
}

export interface ChatMessage {
  id: string
  exchangeId: string
  author: 'human' | 'agent'
  content: string
  isError: boolean
  metadata: Record<string, unknown> | null
  createdAt: string
}

export interface ChatExchange {
  humanMessage: ChatMessage
  agentMessage: ChatMessage
}

export interface TokenUsageEntry {
  id: string
  model: string
  inputTokens: number
  outputTokens: number
  totalTokens: number
  durationMs: number | null
  task: { id: string; title: string } | null
  createdAt: string
}

export interface TokenSummary {
  total: { input: number; output: number; calls: number }
  byAgent: Array<{ agentId: string; totalInput: string; totalOutput: string; calls: string }>
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
  storyStatusTrigger: string | null
  lastOutput: string | null
}

export type WorkflowStatus = 'draft' | 'validated' | 'locked'

export interface Workflow {
  id: string
  name: string
  description: string | null
  trigger: 'manual' | 'vcs_event' | 'scheduled'
  team: { id: string; name: string } | null
  status: WorkflowStatus
  isEditable: boolean
  isUsable: boolean
  steps: WorkflowStep[] | number
  createdAt: string
  updatedAt: string
}

export type AgentRuntimeStatus = 'working' | 'idle' | 'error'

export interface AgentStatus {
  status: AgentRuntimeStatus
  activeTaskCount: number
}

export interface AuditLog {
  id: string
  action: string
  entityType: string
  entityId: string | null
  data: Record<string, unknown> | null
  createdAt: string
}

export interface LogOccurrence {
  id: string
  category: string
  level: string
  fingerprint: string
  title: string
  message: string
  source: string
  projectId: string | null
  taskId: string | null
  agentId: string | null
  firstSeenAt: string
  lastSeenAt: string
  occurrenceCount: number
  status: string
  lastLogEventId: string | null
  contextSnapshot: Record<string, unknown> | null
}

export interface LogEvent {
  id: string
  source: string
  category: string
  level: string
  title: string
  message: string
  fingerprint: string | null
  projectId: string | null
  taskId: string | null
  agentId: string | null
  exchangeRef: string | null
  requestRef: string | null
  traceRef: string | null
  context: Record<string, unknown> | null
  stack: string | null
  origin: string | null
  rawPayload: Record<string, unknown> | null
  occurredAt: string
}

export interface ConnectorHealth {
  status: 'ok' | 'degraded'
  connectors: Record<string, boolean>
}
