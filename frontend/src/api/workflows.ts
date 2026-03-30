/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import apiClient from './client'
import type { Workflow } from '@/types'

export interface WorkflowPayload {
  name: string
  description?: string
  trigger: 'manual' | 'vcs_event' | 'scheduled'
  teamId?: string
}

export const workflowsApi = {
  list: async (): Promise<Workflow[]> => {
    const { data } = await apiClient.get('/workflows')
    return data
  },

  get: async (id: string): Promise<Workflow> => {
    const { data } = await apiClient.get(`/workflows/${id}`)
    return data
  },

  create: async (payload: WorkflowPayload): Promise<Workflow> => {
    const { data } = await apiClient.post('/workflows', payload)
    return data
  },

  update: async (id: string, payload: WorkflowPayload): Promise<Workflow> => {
    const { data } = await apiClient.put(`/workflows/${id}`, payload)
    return data
  },

  delete: async (id: string): Promise<void> => {
    await apiClient.delete(`/workflows/${id}`)
  },

  /** Legacy endpoint kept for backward compatibility; immutable workflows reject it. */
  validate: async (id: string): Promise<{ id: string; status: string }> => {
    const { data } = await apiClient.post(`/workflows/${id}/validate`)
    return data
  },
}
