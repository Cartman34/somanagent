/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import apiClient from './client'
import type { Feature } from '@/types'

/** Payload for creating or updating a feature. */
export interface FeaturePayload {
  name: string
  description?: string
  status?: 'open' | 'in_progress' | 'closed'
}

/** API client for feature CRUD operations within projects. */
export const featuresApi = {
  listByProject: async (projectId: string): Promise<Feature[]> => {
    const { data } = await apiClient.get(`/projects/${projectId}/features`)
    return data
  },

  get: async (id: string): Promise<Feature> => {
    const { data } = await apiClient.get(`/features/${id}`)
    return data
  },

  create: async (projectId: string, payload: FeaturePayload): Promise<Feature> => {
    const { data } = await apiClient.post(`/projects/${projectId}/features`, payload)
    return data
  },

  update: async (id: string, payload: FeaturePayload): Promise<Feature> => {
    const { data } = await apiClient.put(`/features/${id}`, payload)
    return data
  },

  delete: async (id: string): Promise<void> => {
    await apiClient.delete(`/features/${id}`)
  },
}
