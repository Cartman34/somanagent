import apiClient from './client'

export interface HealthStatus {
  status: 'ok' | 'degraded'
  version: string
  timestamp: string
}

export interface ConnectorHealth {
  status: 'ok' | 'degraded'
  connectors: Record<string, boolean>
}

export const healthApi = {
  check: async (): Promise<HealthStatus> => {
    const { data } = await apiClient.get('/health')
    return data
  },

  connectors: async (): Promise<ConnectorHealth> => {
    const { data } = await apiClient.get('/health/connectors')
    return data
  },
}
