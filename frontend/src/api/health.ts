/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import apiClient from './client'

/** Overall application health status. */
export interface HealthStatus {
  status: 'ok' | 'degraded'
  version: string
  timestamp: string
}

/** Health entry for one connector. */
export interface ConnectorHealthEntry {
  label: string
  ok: boolean
  reason: string | null
  fixCommand: string | null
  /** Auth method reported by the connector (e.g. 'claude.ai', 'api_key', 'chatgpt'). Null when not authenticated or not applicable. */
  authMethod: string | null
}

/** Health status of all registered connectors. */
export interface ConnectorHealth {
  status: 'ok' | 'degraded'
  connectors: Record<string, ConnectorHealthEntry>
}

/** Health status of Claude CLI authentication. */
export interface ClaudeCliAuthHealth {
  status: 'ok' | 'degraded'
  loggedIn: boolean
  authMethod: string | null
  apiProvider: string | null
  raw: Record<string, unknown> | null
  error: string | null
}

/** Runtime authentication status for one connector, as returned by the generic connector auth endpoint. */
export interface ConnectorAuthStatus {
  /** Internal status: 'ok' when healthy, 'missing' when not authenticated, 'misconfigured' for wrong auth method. */
  status: 'ok' | 'missing' | 'misconfigured' | string
  required: boolean
  authenticated: boolean
  method: string | null
  supportsAccountUsage: boolean | null
  usesAccountUsage: boolean | null
  summary: string | null
  error: string | null
  metadata: Record<string, unknown>
}

/** API client for application and connector health endpoints. */
export const healthApi = {
  check: async (): Promise<HealthStatus> => {
    const { data } = await apiClient.get('/health')
    return data
  },

  connectors: async (options: { refresh?: boolean } = {}): Promise<ConnectorHealth> => {
    const { data } = await apiClient.get('/health/connectors', {
      params: options.refresh ? { refresh: 1 } : undefined,
    })
    return data
  },

  claudeCliAuth: async (): Promise<ClaudeCliAuthHealth> => {
    const { data } = await apiClient.get('/health/claude-cli-auth')
    return data
  },

  connectorAuth: async (connector: string): Promise<ConnectorAuthStatus> => {
    const { data } = await apiClient.get(`/health/connectors/${connector}/auth`)
    return data
  },

  /**
   * Sends a minimal test prompt through the connector adapter via FPM and returns the result.
   * Exercises the same execution path as the agent chat UI.
   */
  connectorTest: async (connector: string, model?: string): Promise<{
    ok: boolean
    connector: string
    model?: string
    response?: string
    inputTokens?: number
    outputTokens?: number
    durationMs?: number
    error?: string
  }> => {
    const params = model ? { model } : {}
    const { data } = await apiClient.post(`/health/connectors/${connector}/test`, null, { params })
    return data
  },
}
