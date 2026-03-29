import apiClient from './client'
import type { Task, TaskReworkTarget, TaskStatus, TaskPriority, TaskType, StoryStatus } from '@/types'

export interface TaskPayload {
  title: string
  description?: string
  type?: TaskType
  priority?: TaskPriority
  featureId?: string
  parentId?: string
  assignedAgentId?: string
}

export interface ProjectRequestPayload {
  title: string
  description?: string
  priority?: TaskPriority
}

export interface ProjectRequestResult extends Task {
  dispatchError?: string
}

export interface TaskCommentPayload {
  content: string
  replyToLogId?: string
  context?: string
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

  createRequest: async (projectId: string, payload: ProjectRequestPayload): Promise<ProjectRequestResult> => {
    const { data } = await apiClient.post(`/projects/${projectId}/requests`, payload)
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

  comment: async (id: string, payload: TaskCommentPayload): Promise<void> => {
    await apiClient.post(`/tasks/${id}/comments`, payload)
  },

  transitionStory: async (id: string, status: StoryStatus): Promise<Task> => {
    const { data } = await apiClient.post(`/tasks/${id}/story-transition`, { status })
    return data
  },

  resume: async (id: string): Promise<{ task: Task; agent: { id: string; name: string }; skill: string }> => {
    const { data } = await apiClient.post(`/tasks/${id}/resume`)
    return data
  },

  listReworkTargets: async (id: string): Promise<TaskReworkTarget[]> => {
    const { data } = await apiClient.get(`/tasks/${id}/rework-targets`)
    return data
  },

  rework: async (id: string, payload: { targetKey: string; objective: string; note?: string }): Promise<{ task: Task; agent: { id: string; name: string }; skill: string; targetKey: string }> => {
    const { data } = await apiClient.post(`/tasks/${id}/rework`, payload)
    return data
  },

  /** Returns available agents for executing this story in its current status. */
  listExecuteAgents: async (id: string): Promise<{ id: string; name: string; role: { slug: string; name: string } | null }[]> => {
    const { data } = await apiClient.get(`/tasks/${id}/execute`)
    return data
  },

  /**
   * Dispatches the story to an agent for execution.
   * If agentId is omitted, the backend auto-selects the first available agent with the right role.
   */
  execute: async (id: string, agentId?: string): Promise<{ task: Task; agent: { id: string; name: string }; skill: string }> => {
    const { data } = await apiClient.post(`/tasks/${id}/execute`, agentId ? { agentId } : {})
    return data
  },

  delete: async (id: string): Promise<void> => {
    await apiClient.delete(`/tasks/${id}`)
  },
}
