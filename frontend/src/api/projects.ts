/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import apiClient from './client'
import type { Project, Module, AuditLog, TokenUsageEntry } from '@/types'

export interface ProjectPayload {
  name: string
  description?: string
  teamId?: string | null
}

export interface ModulePayload {
  name: string
  description?: string
  repositoryUrl?: string
  stack?: string
  status?: 'active' | 'archived'
}

export const projectsApi = {
  list: async (): Promise<Project[]> => {
    const { data } = await apiClient.get('/projects')
    return data
  },

  get: async (id: string): Promise<Project> => {
    const { data } = await apiClient.get(`/projects/${id}`)
    return data
  },

  create: async (payload: ProjectPayload): Promise<Project> => {
    const { data } = await apiClient.post('/projects', payload)
    return data
  },

  update: async (id: string, payload: ProjectPayload): Promise<Project> => {
    const { data } = await apiClient.put(`/projects/${id}`, payload)
    return data
  },

  delete: async (id: string): Promise<void> => {
    await apiClient.delete(`/projects/${id}`)
  },

  addModule: async (projectId: string, payload: ModulePayload): Promise<Module> => {
    const { data } = await apiClient.post(`/projects/${projectId}/modules`, payload)
    return data
  },

  updateModule: async (moduleId: string, payload: ModulePayload): Promise<Module> => {
    const { data } = await apiClient.put(`/projects/modules/${moduleId}`, payload)
    return data
  },

  deleteModule: async (moduleId: string): Promise<void> => {
    await apiClient.delete(`/projects/modules/${moduleId}`)
  },

  /**
   * Returns the audit log scoped to this project and its tasks, paginated.
   */
  getAudit: async (projectId: string, page = 1, limit = 25): Promise<{ data: AuditLog[]; total: number; page: number; limit: number }> => {
    const { data } = await apiClient.get(`/projects/${projectId}/audit`, { params: { page, limit } })
    return data
  },

  /**
   * Returns token usage summary (by agent) and recent entries for this project.
   */
  getTokens: async (projectId: string): Promise<{
    summary: { total: { input: number; output: number; calls: number }; byAgent: { agentId: string; agentName: string; totalInput: number; totalOutput: number; calls: number }[] }
    entries: TokenUsageEntry[]
  }> => {
    const { data } = await apiClient.get(`/projects/${projectId}/tokens`)
    return data
  },
}
