/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import type { RealtimeUpdateEvent } from '@/types'

const REALTIME_DEBUG_STORAGE_KEY = 'so.realtime.debug'

/** Minimal health snapshot exposed by the realtime diagnostics service. */
export interface RealtimeHealthSnapshot {
  started: boolean
  healthy: boolean
  activeSubscriptions: number
  openSubscriptions: number
  reconnectingSubscriptions: number
}

type ManagedSubscriptionStatus = 'starting' | 'open' | 'reconnecting' | 'closed' | 'error'

interface ManagedSubscriptionState {
  label: string
  status: ManagedSubscriptionStatus
  lastError: string | null
}

declare global {
  interface Window {
    so?: {
      realtimeDiagnostics?: RealtimeDiagnosticsService
    }
  }
}

/**
 * Shared frontend service responsible for realtime debug toggles and health inspection.
 */
export class RealtimeDiagnosticsService {
  private readonly subscriptions = new Map<string, ManagedSubscriptionState>()

  private debugEnabled = this.readPersistedDebugFlag()

  public constructor() {
    this.exposeToConsole()
  }

  /**
   * Registers one managed subscription lifecycle in the diagnostics state.
   */
  public registerSubscription(subscriptionId: string, label: string): void {
    this.subscriptions.set(subscriptionId, {
      label,
      status: 'starting',
      lastError: null,
    })
  }

  /**
   * Updates the tracked lifecycle state for one realtime subscription.
   */
  public updateSubscriptionState(
    subscriptionId: string,
    status: ManagedSubscriptionStatus,
    lastError: string | null = null,
  ): void {
    const state = this.subscriptions.get(subscriptionId)
    if (!state) {
      return
    }

    state.status = status
    state.lastError = lastError
  }

  /**
   * Removes one managed subscription from the diagnostics registry.
   */
  public removeSubscription(subscriptionId: string): void {
    this.subscriptions.delete(subscriptionId)
  }

  /**
   * Enables or disables verbose streamed message logging.
   */
  public setDebugEnabled(enabled: boolean): void {
    this.debugEnabled = enabled
    this.persistDebugFlag(enabled)

    console.info('[realtime] Debug logging toggled.', {
      enabled,
    })
  }

  /**
   * Returns whether verbose streamed message logging is active.
   */
  public isDebugEnabled(): boolean {
    return this.debugEnabled
  }

  /**
   * Logs one streamed message when debug mode is enabled.
   */
  public logMessage(subscriptionId: string, label: string, event: RealtimeUpdateEvent): void {
    if (!this.debugEnabled) {
      return
    }

    console.info(`[realtime] Message received: ${event.type}`, {
      subscriptionId,
      label,
      type: event.type,
      event,
    })
  }

  /**
   * Returns the current realtime health snapshot.
   */
  public getHealthSnapshot(): RealtimeHealthSnapshot {
    const states = Array.from(this.subscriptions.values())
    const openSubscriptions = states.filter((state) => state.status === 'open').length
    const reconnectingSubscriptions = states.filter((state) => state.status === 'reconnecting').length

    return {
      started: true,
      healthy: states.length > 0 && openSubscriptions > 0 && states.every((state) => state.status !== 'error'),
      activeSubscriptions: states.length,
      openSubscriptions,
      reconnectingSubscriptions,
    }
  }

  /**
   * Logs a health verdict and returns whether the current realtime state is healthy.
   */
  public assertHealthy(): boolean {
    const health = this.getHealthSnapshot()
    if (!health.healthy) {
      console.error('[realtime] Health check failed.', health)
      return false
    }

    console.info('[realtime] Health check passed.', health)
    return true
  }

  private exposeToConsole(): void {
    window.so ??= {}
    window.so.realtimeDiagnostics = this
  }

  private readPersistedDebugFlag(): boolean {
    try {
      return window.sessionStorage.getItem(REALTIME_DEBUG_STORAGE_KEY) === '1'
    } catch {
      return false
    }
  }

  private persistDebugFlag(enabled: boolean): void {
    try {
      window.sessionStorage.setItem(REALTIME_DEBUG_STORAGE_KEY, enabled ? '1' : '0')
    } catch {
      // Ignore storage failures so diagnostics never break the app.
    }
  }
}

function getRealtimeDiagnosticsService(): RealtimeDiagnosticsService {
  window.so ??= {}

  if (window.so.realtimeDiagnostics instanceof RealtimeDiagnosticsService) {
    return window.so.realtimeDiagnostics
  }

  const service = new RealtimeDiagnosticsService()
  window.so.realtimeDiagnostics = service

  return service
}

/** Shared frontend service for realtime debugging and health inspection. */
export const realtimeDiagnosticsService = getRealtimeDiagnosticsService()
