/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import apiClient from './client'
import type { TokenSummary, TokenUsageEntry } from '@/types'

export const tokensApi = {
  summary: async (from?: string, to?: string): Promise<TokenSummary> => {
    const params = new URLSearchParams()
    if (from) params.set('from', from)
    if (to)   params.set('to', to)
    const { data } = await apiClient.get(`/tokens/summary?${params}`)
    return data
  },

  byAgent: async (agentId: string, limit = 100): Promise<TokenUsageEntry[]> => {
    const { data } = await apiClient.get(`/tokens/agents/${agentId}?limit=${limit}`)
    return data
  },
}
