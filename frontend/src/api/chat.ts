import apiClient from './client'
import type { ChatMessage } from '@/types'

export const chatApi = {
  history: async (projectId: string, agentId: string): Promise<ChatMessage[]> => {
    const { data } = await apiClient.get(`/projects/${projectId}/chat/${agentId}`)
    return data
  },

  send: async (projectId: string, agentId: string, content: string): Promise<ChatMessage> => {
    const { data } = await apiClient.post(`/projects/${projectId}/chat/${agentId}`, { content })
    return data
  },
}
