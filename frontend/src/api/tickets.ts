/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import apiClient from './client'
import type { Ticket, TicketTask, TaskPriority, TaskStatus, TaskType, StoryStatus, TaskReworkTarget } from '@/types'

export interface TicketPayload {
  title: string
  description?: string
  type?: Exclude<TaskType, 'task'>
  priority?: TaskPriority
  featureId?: string
}

export interface TicketTaskPayload {
  title: string
  description?: string
  priority?: TaskPriority
  actionKey?: string
  parentTaskId?: string
  assignedAgentId?: string
}

export interface TicketCommentPayload {
  content: string
  replyToLogId?: string
  context?: string
}

export interface ProjectRequestPayload {
  title: string
  description?: string
  priority?: TaskPriority
}

export interface ProjectRequestResult extends Ticket {
  dispatchError?: string
}

export const ticketsApi = {
  listByProject: async (projectId: string): Promise<Ticket[]> => {
    const { data } = await apiClient.get(`/projects/${projectId}/tickets`)
    return data
  },

  create: async (projectId: string, payload: TicketPayload): Promise<Ticket> => {
    const { data } = await apiClient.post(`/projects/${projectId}/tickets`, payload)
    return data
  },

  createRequest: async (projectId: string, payload: ProjectRequestPayload): Promise<ProjectRequestResult> => {
    const { data } = await apiClient.post(`/projects/${projectId}/requests`, payload)
    return data
  },

  get: async (id: string): Promise<Ticket> => {
    const { data } = await apiClient.get(`/tickets/${id}`)
    return data
  },

  update: async (id: string, payload: Partial<TicketPayload>): Promise<Ticket> => {
    const { data } = await apiClient.put(`/tickets/${id}`, payload)
    return data
  },

  changeStatus: async (id: string, status: TaskStatus): Promise<Ticket> => {
    const { data } = await apiClient.patch(`/tickets/${id}/status`, { status })
    return data
  },

  reprioritize: async (id: string, priority: TaskPriority): Promise<Ticket> => {
    const { data } = await apiClient.patch(`/tickets/${id}/priority`, { priority })
    return data
  },

  transitionStory: async (id: string, status: StoryStatus): Promise<Ticket> => {
    const { data } = await apiClient.post(`/tickets/${id}/story-transition`, { status })
    return data
  },

  listExecuteAgents: async (id: string): Promise<{ id: string; name: string; role: { slug: string; name: string } | null }[]> => {
    const { data } = await apiClient.get(`/tickets/${id}/execute`)
    return data
  },

  execute: async (id: string, agentId?: string): Promise<{ ticket: Ticket; agent: { id: string; name: string }; skill: string }> => {
    const { data } = await apiClient.post(`/tickets/${id}/execute`, agentId ? { agentId } : {})
    return data
  },

  resume: async (id: string): Promise<{ ticket: Ticket; agent: { id: string; name: string }; skill: string }> => {
    const { data } = await apiClient.post(`/tickets/${id}/resume`)
    return data
  },

  listReworkTargets: async (id: string): Promise<TaskReworkTarget[]> => {
    const { data } = await apiClient.get(`/tickets/${id}/rework-targets`)
    return data
  },

  rework: async (id: string, payload: { targetKey: string; objective: string; note?: string }): Promise<{ ticket: Ticket; agent: { id: string; name: string }; skill: string; targetKey: string }> => {
    const { data } = await apiClient.post(`/tickets/${id}/rework`, payload)
    return data
  },

  comment: async (id: string, payload: TicketCommentPayload) => {
    const { data } = await apiClient.post(`/tickets/${id}/comments`, payload)
    return data
  },

  delete: async (id: string): Promise<void> => {
    await apiClient.delete(`/tickets/${id}`)
  },
}

export const ticketTasksApi = {
  create: async (ticketId: string, payload: TicketTaskPayload): Promise<TicketTask> => {
    const { data } = await apiClient.post(`/tickets/${ticketId}/tasks`, payload)
    return data
  },

  get: async (id: string): Promise<TicketTask> => {
    const { data } = await apiClient.get(`/ticket-tasks/${id}`)
    return data
  },

  update: async (id: string, payload: Partial<TicketTaskPayload>): Promise<TicketTask> => {
    const { data } = await apiClient.put(`/ticket-tasks/${id}`, payload)
    return data
  },

  changeStatus: async (id: string, status: TaskStatus): Promise<TicketTask> => {
    const { data } = await apiClient.patch(`/ticket-tasks/${id}/status`, { status })
    return data
  },

  updateProgress: async (id: string, progress: number): Promise<TicketTask> => {
    const { data } = await apiClient.patch(`/ticket-tasks/${id}/progress`, { progress })
    return data
  },

  reprioritize: async (id: string, priority: TaskPriority): Promise<TicketTask> => {
    const { data } = await apiClient.patch(`/ticket-tasks/${id}/priority`, { priority })
    return data
  },

  validate: async (id: string): Promise<TicketTask> => {
    const { data } = await apiClient.post(`/ticket-tasks/${id}/validate`)
    return data
  },

  reject: async (id: string, reason?: string): Promise<TicketTask> => {
    const { data } = await apiClient.post(`/ticket-tasks/${id}/reject`, { reason })
    return data
  },

  requestValidation: async (id: string, comment?: string): Promise<TicketTask> => {
    const { data } = await apiClient.post(`/ticket-tasks/${id}/request-validation`, { comment })
    return data
  },

  comment: async (id: string, payload: TicketCommentPayload) => {
    const { data } = await apiClient.post(`/ticket-tasks/${id}/comments`, payload)
    return data
  },

  delete: async (id: string): Promise<void> => {
    await apiClient.delete(`/ticket-tasks/${id}`)
  },
}
