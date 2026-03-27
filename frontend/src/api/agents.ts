import apiClient from './client'
import type { Agent, AgentConfig, AgentStatus } from '@/types'

export interface AgentPayload {
  name: string
  description?: string
  connector: 'claude_api' | 'claude_cli'
  roleId?: string
  isActive?: boolean
  config?: Partial<AgentConfig>
}

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
   * Status is computed server-side from active tasks and recent error logs.
   */
  getStatus: async (id: string): Promise<AgentStatus> => {
    const { data } = await apiClient.get(`/agents/${id}/status`)
    return data
  },
}
