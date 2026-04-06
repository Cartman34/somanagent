/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import apiClient from './client'
import type { Skill } from '@/types'

/** Payload for creating a custom skill. */
export interface SkillCreatePayload {
  name: string
  slug: string
  description?: string
  content: string
}

/** API client for skill CRUD operations and marketplace import. */
export const skillsApi = {
  list: async (): Promise<Skill[]> => {
    const { data } = await apiClient.get('/skills')
    return data
  },

  get: async (id: string): Promise<Skill> => {
    const { data } = await apiClient.get(`/skills/${id}`)
    return data
  },

  create: async (payload: SkillCreatePayload): Promise<Skill> => {
    const { data } = await apiClient.post('/skills', payload)
    return data
  },

  import: async (ownerAndName: string): Promise<Skill[]> => {
    const { data } = await apiClient.post('/skills/import', { ownerAndName })
    return data
  },

  updateContent: async (id: string, content: string): Promise<Skill> => {
    const { data } = await apiClient.put(`/skills/${id}/content`, { content })
    return data
  },

  delete: async (id: string): Promise<void> => {
    await apiClient.delete(`/skills/${id}`)
  },
}
