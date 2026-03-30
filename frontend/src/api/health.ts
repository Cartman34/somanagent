/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

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

export interface ClaudeCliAuthHealth {
  status: 'ok' | 'degraded'
  loggedIn: boolean
  authMethod: string | null
  apiProvider: string | null
  raw: Record<string, unknown> | null
  error: string | null
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

  claudeCliAuth: async (): Promise<ClaudeCliAuthHealth> => {
    const { data } = await apiClient.get('/health/claude-cli-auth')
    return data
  },
}
