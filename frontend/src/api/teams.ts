import apiClient from './client'
import type { Team, Role } from '@/types'

export interface TeamPayload {
  name: string
  description?: string
}

export interface RolePayload {
  name: string
  description?: string
  skillSlug?: string
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

  addRole: async (teamId: string, payload: RolePayload): Promise<Role> => {
    const { data } = await apiClient.post(`/teams/${teamId}/roles`, payload)
    return data
  },

  updateRole: async (roleId: string, payload: RolePayload): Promise<Role> => {
    const { data } = await apiClient.put(`/teams/roles/${roleId}`, payload)
    return data
  },

  deleteRole: async (roleId: string): Promise<void> => {
    await apiClient.delete(`/teams/roles/${roleId}`)
  },
}
