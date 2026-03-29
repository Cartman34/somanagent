import apiClient from './client'
import type { LogEvent, LogOccurrence } from '@/types'

export interface LogFilters {
  source?: string
  category?: string
  level?: string
  projectId?: string
  taskId?: string
  agentId?: string
  status?: string
  from?: string
  to?: string
  page?: number
  limit?: number
}

/**
 * Payload used to update the triage status of an aggregated log occurrence.
 */
export interface LogOccurrenceStatusPayload {
  status: LogOccurrence['status']
}

export const logsApi = {
  listOccurrences: async (filters: LogFilters): Promise<{ data: LogOccurrence[]; total: number; page: number; limit: number }> => {
    const { data } = await apiClient.get('/logs/occurrences', { params: filters })
    return data
  },

  listEvents: async (filters: LogFilters): Promise<{ data: LogEvent[]; total: number; page: number; limit: number }> => {
    const { data } = await apiClient.get('/logs/events', { params: filters })
    return data
  },

  getOccurrence: async (id: string): Promise<{ occurrence: LogOccurrence; events: LogEvent[] }> => {
    const { data } = await apiClient.get(`/logs/occurrences/${id}`)
    return data
  },

  updateOccurrenceStatus: async (id: string, payload: LogOccurrenceStatusPayload): Promise<{ occurrence: LogOccurrence }> => {
    const { data } = await apiClient.patch(`/logs/occurrences/${id}/status`, payload)
    return data
  },
}
