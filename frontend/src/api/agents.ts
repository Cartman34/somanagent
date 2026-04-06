/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import apiClient from './client'
import type { Agent, AgentConfig, AgentStatus, AgentTaskExecutionResourceSnapshot } from '@/types'

/** Payload for creating or updating an agent. */
export interface AgentPayload {
  name: string
  description?: string
  connector: 'claude_api' | 'claude_cli'
  roleId?: string
  isActive?: boolean
  config?: Partial<AgentConfig>
}

/** API client for agent CRUD operations and status lookup. */
export const agentsApi = {
  list: async (): Promise<Agent[]> => {
    const { data } = await apiClient.get('/agents')
    return data
  },

  get: async (id: string): Promise<Agent> => {
    const { data } = await apiClient.get(`/agents/${id}`)
    return data
  },

  create: async (payload: AgentPayload): Promise<Agent> => {
    const { data } = await apiClient.post('/agents', payload)
    return data
  },

  update: async (id: string, payload: AgentPayload): Promise<Agent> => {
    const { data } = await apiClient.put(`/agents/${id}`, payload)
    return data
  },

  delete: async (id: string): Promise<void> => {
    await apiClient.delete(`/agents/${id}`)
  },

  /**
   * Returns the derived runtime status of an agent.
   * Status is computed server-side from active tasks and the latest runtime signal.
   */
  getStatus: async (id: string): Promise<AgentStatus> => {
    const { data } = await apiClient.get(`/agents/${id}/status`)
    return data
  },

  /**
   * Returns the execution history for a given agent.
   */
  getExecutions: async (id: string): Promise<AgentExecution[]> => {
    const { data } = await apiClient.get(`/agents/${id}/executions`)
    return data
  },
}

/** Linked ticket task with its parent ticket title. */
export interface ExecutionTicketTask {
  id: string
  ticketId: string
  ticketTitle: string
  title: string
}

/** Single attempt within an execution. */
export interface ExecutionAttempt {
  id: string
  attemptNumber: number
  status: string
  willRetry: boolean
  messengerReceiver: string | null
  requestRef: string | null
  errorMessage: string | null
  errorScope: string | null
  resourceSnapshot: AgentTaskExecutionResourceSnapshot | null
  startedAt: string | null
  finishedAt: string | null
  agent: { id: string; name: string } | null
}

/** Agent execution record returned by the API. */
export interface AgentExecution {
  id: string
  traceRef: string
  triggerType: string
  actionKey: string
  actionLabel: string | null
  roleSlug: string | null
  skillSlug: string | null
  status: string
  currentAttempt: number
  maxAttempts: number
  requestRef: string | null
  lastErrorMessage: string | null
  lastErrorScope: string | null
  createdAt: string
  startedAt: string | null
  finishedAt: string | null
  requestedAgent: { id: string; name: string } | null
  effectiveAgent: { id: string; name: string } | null
  ticketTasks: ExecutionTicketTask[]
  attempts: ExecutionAttempt[]
}
