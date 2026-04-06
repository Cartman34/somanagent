/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import apiClient from './client'
import type { Role } from '@/types'

/** Payload for creating or updating a role. */
export interface RolePayload {
  slug: string
  name: string
  description?: string
}

/** API client for role CRUD operations and skill assignments. */
export const rolesApi = {
  list: async (): Promise<Role[]> => {
    const { data } = await apiClient.get('/roles')
    return data
  },

  get: async (id: string): Promise<Role> => {
    const { data } = await apiClient.get(`/roles/${id}`)
    return data
  },

  create: async (payload: RolePayload): Promise<Role> => {
    const { data } = await apiClient.post('/roles', payload)
    return data
  },

  update: async (id: string, payload: RolePayload): Promise<Role> => {
    const { data } = await apiClient.put(`/roles/${id}`, payload)
    return data
  },

  delete: async (id: string): Promise<void> => {
    await apiClient.delete(`/roles/${id}`)
  },

  addSkill: async (roleId: string, skillId: string): Promise<void> => {
    await apiClient.post(`/roles/${roleId}/skills`, { skillId })
  },

  removeSkill: async (roleId: string, skillId: string): Promise<void> => {
    await apiClient.delete(`/roles/${roleId}/skills/${skillId}`)
  },
}
