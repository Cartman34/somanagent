/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import apiClient from './client'
import type { Workflow } from '@/types'

/** Payload for creating or updating a workflow. */
export interface WorkflowPayload {
  name: string
  description?: string
  trigger: 'manual' | 'vcs_event' | 'scheduled'
}

/** Response from activating a workflow. */
export interface WorkflowActivationResponse {
  id: string
  isActive: boolean
}

/** Response from duplicating a workflow. */
export interface WorkflowDuplicateResponse {
  id: string
  name: string
  isActive: boolean
}

/** API client for workflow CRUD operations and lifecycle actions. */
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

  duplicate: async (id: string): Promise<WorkflowDuplicateResponse> => {
    const { data } = await apiClient.post(`/workflows/${id}/duplicate`)
    return data
  },

  activate: async (id: string): Promise<WorkflowActivationResponse> => {
    const { data } = await apiClient.post(`/workflows/${id}/activate`)
    return data
  },

  deactivate: async (id: string): Promise<WorkflowActivationResponse> => {
    const { data } = await apiClient.post(`/workflows/${id}/deactivate`)
    return data
  },

  update: async (id: string, payload: WorkflowPayload): Promise<Workflow> => {
    const { data } = await apiClient.put(`/workflows/${id}`, payload)
    return data
  },

  delete: async (id: string): Promise<void> => {
    await apiClient.delete(`/workflows/${id}`)
  },
}
