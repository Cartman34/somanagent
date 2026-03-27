import apiClient from './client'
import type { Project, Module } from '@/types'

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
}
