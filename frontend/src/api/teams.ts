/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import apiClient from './client'
import type { Team } from '@/types'

export interface TeamPayload {
  name: string
  description?: string
}

export const teamsApi = {
  list: async (): Promise<Team[]> => {
    const { data } = await apiClient.get('/teams')
    return data
  },

  get: async (id: string): Promise<Team> => {
    const { data } = await apiClient.get(`/teams/${id}`)
    return data
  },

  create: async (payload: TeamPayload): Promise<Team> => {
    const { data } = await apiClient.post('/teams', payload)
    return data
  },

  update: async (id: string, payload: TeamPayload): Promise<Team> => {
    const { data } = await apiClient.put(`/teams/${id}`, payload)
    return data
  },

  delete: async (id: string): Promise<void> => {
    await apiClient.delete(`/teams/${id}`)
  },

  addAgent: async (teamId: string, agentId: string): Promise<void> => {
    await apiClient.post(`/teams/${teamId}/agents`, { agentId })
  },

  removeAgent: async (teamId: string, agentId: string): Promise<void> => {
    await apiClient.delete(`/teams/${teamId}/agents/${agentId}`)
  },
}
