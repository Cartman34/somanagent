/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

// ─────────────────────────────────────────────────────────────────────────────
// Shared SoManAgent types aligned with PHP API responses
// ─────────────────────────────────────────────────────────────────────────────

/** Project summary and detail payload returned by the backend API. */
export interface Project {
  id: string
  name: string
  description: string | null
  repositoryUrl: string | null
  team: { id: string; name: string } | null
  workflow: { id: string; name: string } | null
  dispatchMode: ProjectDispatchMode
  modules: number | Module[]
  createdAt: string
  updatedAt: string
}

/** Application module attached to one project. */
export interface Module {
  id: string
  name: string
  description: string | null
  repositoryUrl: string | null
  stack: string | null
  status: 'active' | 'archived'
}

/** Team entity with optional expanded agent list. */
export interface Team {
  id: string
  name: string
  description: string | null
  agentCount: number
  agents?: AgentSummary[]
  createdAt: string
  updatedAt: string
}

/** Role definition with optional linked skills. */
export interface Role {
  id: string
  slug: string
  name: string
  description: string | null
  skills?: SkillSummary[]
}

/** Lightweight skill reference embedded in other payloads. */
export interface SkillSummary {
  id: string
  name: string
  slug?: string
  description?: string | null
  content?: string
  filePath?: string
}

/** Condensed agent payload used in lists and relations. */
export interface AgentSummary {
  id: string
  name: string
  isActive: boolean
  role: { id: string; name: string; slug: string } | null
}

/** Runtime configuration forwarded to an agent connector. */
export interface AgentConfig {
  model: string
  max_tokens: number
  temperature: number
  timeout: number
  extra?: Record<string, unknown>
}

/** Full agent record exposed by the API. */
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

/** Full skill record exposed by the API. */
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

/** Product feature tracked for one project. */
export interface Feature {
  id: string
  name: string
  description: string | null
  status: 'open' | 'in_progress' | 'closed'
  project?: { id: string; name: string }
  createdAt: string
  updatedAt: string
}

/** Project-level dispatch policy for eligible agent tasks. */
export type ProjectDispatchMode = 'auto' | 'manual'
/** Domain-level task kind used for tickets and operational tasks. */
export type TaskType     = 'user_story' | 'bug' | 'task'
/** Business lifecycle state shared by tickets and ticket tasks. */
export type TaskStatus   = 'backlog' | 'todo' | 'awaiting_dispatch' | 'in_progress' | 'review' | 'done' | 'cancelled'
/** Priority scale used by tickets and ticket tasks. */
export type TaskPriority = 'low' | 'medium' | 'high' | 'critical'

/** Lightweight workflow step reference. */
export interface WorkflowStepRef {
  id: string
  key?: string
  name: string
}

/** Lightweight agent reference. */
export interface AgentRef {
  id: string
  name: string
}

/** Lightweight role reference. */
export interface RoleRef {
  id: string
  slug: string
  name: string
}

/** Lightweight feature reference. */
export interface FeatureRef {
  id: string
  name: string
}

/** Lightweight skill reference. */
export interface SkillRef {
  id: string
  slug: string
  name: string
}

/** Agent action metadata embedded in task payloads. */
export interface AgentActionRef {
  id: string
  key: string
  label: string
  role: RoleRef | null
  skill: SkillRef | null
}

/** One execution attempt for an async agent run. */
export interface AgentTaskExecutionAttempt {
  id: string
  attemptNumber: number
  status: 'running' | 'succeeded' | 'failed'
  willRetry: boolean
  messengerReceiver: string | null
  requestRef: string | null
  errorMessage: string | null
  errorScope: string | null
  resourceSnapshot?: AgentTaskExecutionResourceSnapshot | null
  startedAt: string | null
  finishedAt: string | null
  agent: { id: string; name: string } | null
}

/** Snapshot of the database-backed agent resource used for one execution. */
export interface ExecutionAgentResourceSnapshot {
  resourceKind: 'database_agent'
  filePath: string | null
  id: string
  name: string
  description: string | null
  connector: string
  role: RoleRef | null
  config: AgentConfig
}

/** Snapshot of the skill resource injected into one execution. */
export interface ExecutionSkillResourceSnapshot {
  resourceKind: 'skill_file'
  id: string
  slug: string
  name: string
  description: string | null
  source: string
  originalSource: string | null
  filePath: string
  content: string
}

/** Snapshot of the prompt material sent to the agent for one execution. */
export interface ExecutionPromptResourceSnapshot {
  instruction: string
  context: Record<string, unknown>
  rendered: string
}

/** Snapshot of runtime execution scope and allowed effects. */
export interface ExecutionScopeResourceSnapshot {
  taskActions: Record<string, unknown>[]
  ticketTransitions: Record<string, unknown>[]
  allowedEffects: string[]
}

/** Capture limits attached to one execution resource snapshot. */
export interface ExecutionResourceSnapshotLimits {
  agentFilePathAvailable: boolean
  agentFilePathReason: string
}

/** Immutable runtime resource snapshot stored on an execution. */
export interface AgentTaskExecutionResourceSnapshot {
  capturedAt: string
  agent: ExecutionAgentResourceSnapshot
  skill: ExecutionSkillResourceSnapshot
  prompt: ExecutionPromptResourceSnapshot
  scope: ExecutionScopeResourceSnapshot
  limits: ExecutionResourceSnapshotLimits
}

/** Aggregate execution record with attempts and agent metadata. */
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

/** Activity log entry attached to a ticket or task. */
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

/** One normalized realtime event emitted through Mercure. */
export interface RealtimeUpdateEvent {
  id: string
  type: 'project.changed' | 'ticket.changed' | 'ticket.deleted' | 'task.changed' | 'task.deleted' | 'ticket.log.changed' | 'execution.changed'
  occurredAt: string
  payload: {
    projectId?: string
    ticketId?: string
    taskId?: string
    executionId?: string
    status?: string
    reason?: string
    progress?: number
    priority?: string
    workflowStepKey?: string
    taskIds?: string[]
    logId?: string
    action?: string
    kind?: string
    requiresAnswer?: boolean
  }
}

/** Operational task nested under one ticket. */
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
  awaitingUserAnswer: boolean
  pendingUserAnswerCount: number
  canResume: boolean
  canAuthorize: boolean
  dependsOn: Array<{ id: string; title: string; status: TaskStatus }>
  childTaskIds: string[]
  children?: TicketTask[]
  executions?: AgentTaskExecution[]
  logs?: TicketLog[]
  tokenUsage?: TokenUsageEntry[]
  createdAt: string
  updatedAt: string
}

/** Top-level board item representing a story or bug. */
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
  awaitingUserAnswer: boolean
  pendingUserAnswerCount: number
  taskCounts: { total: number; root: number; activeStep: number }
  activeStepTasks?: TicketTask[]
  tasks?: TicketTask[]
  executions?: AgentTaskExecution[]
  logs?: TicketLog[]
  tokenUsage?: TokenUsageEntry[]
  createdAt: string
  updatedAt: string
}

/** One message exchanged in the project chat surface. */
export interface ChatMessage {
  id: string
  exchangeId: string
  replyToMessageId?: string
  author: 'human' | 'agent'
  content: string
  isError: boolean
  metadata: Record<string, unknown> | null
  createdAt: string
}

/** Paired human and agent messages for one exchange. */
export interface ChatExchange {
  humanMessage: ChatMessage
  agentMessage: ChatMessage
}

/** Token accounting record for one backend call. */
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

/** Aggregated token usage statistics. */
export interface TokenSummary {
  total: { input: number; output: number; calls: number }
  byAgent: Array<{ agentId: string; totalInput: string; totalOutput: string; calls: string }>
}

/** Workflow step configuration returned by workflow endpoints. */
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

/** Workflow definition associated with one project. */
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

/** High-level runtime availability exposed for one agent. */
export type AgentRuntimeStatus = 'working' | 'idle' | 'error'

/** Runtime health snapshot for one agent. */
export interface AgentStatus {
  status: AgentRuntimeStatus
  activeTaskCount: number
  lastRuntimeSignal?: {
    action: string
    createdAt: string
  } | null
}

/** Persisted audit trail entry. */
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

/** Aggregated operational occurrence used in log monitoring views. */
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

/** Raw operational log event returned by observability APIs. */
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

/** Health summary for external agent connectors. */
export interface ConnectorHealth {
  status: 'ok' | 'degraded'
  connectors: Record<string, boolean>
}
