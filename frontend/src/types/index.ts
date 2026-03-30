/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

// ─────────────────────────────────────────────────────────────────────────────
// Shared SoManAgent types aligned with PHP API responses
// ─────────────────────────────────────────────────────────────────────────────

export interface Project {
  id: string
  name: string
  description: string | null
  repositoryUrl: string | null
  team: { id: string; name: string } | null
  workflow: { id: string; name: string } | null
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

export interface WorkflowStepRef {
  id: string
  key?: string
  name: string
}

export interface AgentRef {
  id: string
  name: string
}

export interface RoleRef {
  id: string
  slug: string
  name: string
}

export interface FeatureRef {
  id: string
  name: string
}

export interface SkillRef {
  id: string
  slug: string
  name: string
}

export interface AgentActionRef {
  id: string
  key: string
  label: string
  role: RoleRef | null
  skill: SkillRef | null
}

export interface AgentTaskExecutionAttempt {
  id: string
  attemptNumber: number
  status: 'running' | 'succeeded' | 'failed'
  willRetry: boolean
  messengerReceiver: string | null
  requestRef: string | null
  errorMessage: string | null
  errorScope: string | null
  startedAt: string | null
  finishedAt: string | null
  agent: { id: string; name: string } | null
}

export interface AgentTaskExecution {
  id: string
  traceRef: string
  triggerType: 'auto' | 'manual' | 'rework' | 'redispatch'
  workflowStepKey: string | null
  actionKey?: string
  actionLabel?: string | null
  roleSlug?: string | null
  skillSlug: string | null
  status: 'pending' | 'running' | 'retrying' | 'succeeded' | 'failed' | 'dead_letter' | 'cancelled'
  currentAttempt: number
  maxAttempts: number
  requestRef: string | null
  lastErrorMessage: string | null
  lastErrorScope: string | null
  startedAt: string | null
  finishedAt: string | null
  requestedAgent: { id: string; name: string } | null
  effectiveAgent: { id: string; name: string } | null
  ticketTaskIds?: string[]
  attempts: AgentTaskExecutionAttempt[]
}

export interface TaskReworkTarget {
  key: string
  label: string
  description: string
  roleSlug: string
  skillSlug: string
  workflowStepKey: string
  availableAgentCount: number
  agent: { id: string; name: string } | null
}

export interface TicketLog {
  id: string
  ticketId?: string
  ticketTaskId?: string | null
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

export interface TicketTask {
  id: string
  ticketId: string
  parentTaskId: string | null
  title: string
  description: string | null
  status: TaskStatus
  priority: TaskPriority
  progress: number
  branchName: string | null
  branchUrl: string | null
  workflowStep: WorkflowStepRef | null
  agentAction: AgentActionRef
  assignedAgent: AgentRef | null
  assignedRole: RoleRef | null
  dependsOn: Array<{ id: string; title: string; status: TaskStatus }>
  childTaskIds: string[]
  children?: TicketTask[]
  executions?: AgentTaskExecution[]
  logs?: TicketLog[]
  tokenUsage?: TokenUsageEntry[]
  createdAt: string
  updatedAt: string
}

export interface Ticket {
  id: string
  projectId: string
  feature: FeatureRef | null
  type: Exclude<TaskType, 'task'>
  title: string
  description: string | null
  status: TaskStatus
  priority: TaskPriority
  progress: number
  branchName: string | null
  branchUrl: string | null
  workflowStep: WorkflowStepRef | null
  workflowStepAllowedTransitions: WorkflowStepRef[]
  assignedAgent: AgentRef | null
  assignedRole: RoleRef | null
  taskCounts: { total: number; root: number; activeStep: number }
  activeStepTasks?: TicketTask[]
  tasks?: TicketTask[]
  executions?: AgentTaskExecution[]
  logs?: TicketLog[]
  tokenUsage?: TokenUsageEntry[]
  createdAt: string
  updatedAt: string
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
  inputConfig: Record<string, unknown>
  outputKey: string
  transitionMode: 'manual' | 'automatic'
  condition: string | null
  status: 'pending' | 'running' | 'done' | 'error' | 'skipped'
  lastOutput: string | null
  actions: Array<{
    id: string
    createWithTicket: boolean
    agentAction: AgentActionRef
  }>
}

export interface Workflow {
  id: string
  name: string
  description: string | null
  trigger: 'manual' | 'vcs_event' | 'scheduled'
  isActive: boolean
  isEditable: boolean
  steps: WorkflowStep[] | number
  createdAt: string
  updatedAt: string
}

export type AgentRuntimeStatus = 'working' | 'idle' | 'error'

export interface AgentStatus {
  status: AgentRuntimeStatus
  activeTaskCount: number
  lastRuntimeSignal?: {
    action: string
    createdAt: string
  } | null
}

export interface AuditLog {
  id: string
  action: string
  entityType: string
  entityId: string | null
  data: Record<string, unknown> | null
  createdAt: string
}

/**
 * Describes a single translation identity with its optional interpolation parameters.
 */
export interface TranslationDescriptor {
  domain: string | null
  key: string | null
  parameters: Record<string, string | number | boolean | null> | null
}

/**
 * Carries the canonical translation metadata returned by persisted backend log APIs.
 */
export interface PersistedI18nMetadata {
  titleDomain: string | null
  titleKey: string | null
  titleParameters: Record<string, string | number | boolean | null> | null
  messageDomain: string | null
  messageKey: string | null
  messageParameters: Record<string, string | number | boolean | null> | null
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
  status: 'open' | 'acknowledged' | 'resolved' | 'ignored'
  lastLogEventId: string | null
  contextSnapshot: Record<string, unknown> | null
  i18n: PersistedI18nMetadata | null
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
  i18n: PersistedI18nMetadata | null
  occurredAt: string
}

export interface ConnectorHealth {
  status: 'ok' | 'degraded'
  connectors: Record<string, boolean>
}
