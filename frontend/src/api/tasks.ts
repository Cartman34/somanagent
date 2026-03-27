import apiClient from './client'
import type { Task, TaskStatus, TaskPriority, TaskType, StoryStatus } from '@/types'

export interface TaskPayload {
  title: string
  description?: string
  type?: TaskType
  priority?: TaskPriority
  featureId?: string
  parentId?: string
  assignedAgentId?: string
}

export const tasksApi = {
  listByProject: async (projectId: string): Promise<Task[]> => {
    const { data } = await apiClient.get(`/projects/${projectId}/tasks`)
    return data
  },

  get: async (id: string): Promise<Task> => {
    const { data } = await apiClient.get(`/tasks/${id}`)
    return data
  },

  create: async (projectId: string, payload: TaskPayload): Promise<Task> => {
    const { data } = await apiClient.post(`/projects/${projectId}/tasks`, payload)
    return data
  },

  update: async (id: string, payload: Partial<TaskPayload>): Promise<Task> => {
    const { data } = await apiClient.put(`/tasks/${id}`, payload)
    return data
  },

  changeStatus: async (id: string, status: TaskStatus): Promise<Task> => {
    const { data } = await apiClient.patch(`/tasks/${id}/status`, { status })
    return data
  },

  updateProgress: async (id: string, progress: number): Promise<Task> => {
    const { data } = await apiClient.patch(`/tasks/${id}/progress`, { progress })
    return data
  },

  reprioritize: async (id: string, priority: TaskPriority): Promise<Task> => {
    const { data } = await apiClient.patch(`/tasks/${id}/priority`, { priority })
    return data
  },

  validate: async (id: string): Promise<Task> => {
    const { data } = await apiClient.post(`/tasks/${id}/validate`)
    return data
  },

  reject: async (id: string, reason?: string): Promise<Task> => {
    const { data } = await apiClient.post(`/tasks/${id}/reject`, { reason })
    return data
  },

  requestValidation: async (id: string, comment?: string): Promise<Task> => {
    const { data } = await apiClient.post(`/tasks/${id}/request-validation`, { comment })
    return data
  },

  transitionStory: async (id: string, status: StoryStatus): Promise<Task> => {
    const { data } = await apiClient.post(`/tasks/${id}/story-transition`, { status })
    return data
  },

  delete: async (id: string): Promise<void> => {
    await apiClient.delete(`/tasks/${id}`)
  },
}
