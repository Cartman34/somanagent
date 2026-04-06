/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import type { RealtimeUpdateEvent } from '@/types'
import { realtimeDiagnosticsService } from '@/lib/realtime/diagnostics'

const REALTIME_EVENT_TYPES: RealtimeUpdateEvent['type'][] = [
  'project.changed',
  'ticket.changed',
  'ticket.deleted',
  'task.changed',
  'task.deleted',
  'ticket.log.changed',
  'execution.changed',
]

/** One active realtime subscription. */
export interface RealtimeSubscription {
  close(reason?: string): void
}

/** Subscription contract for the shared realtime client. */
export interface RealtimeSubscriptionOptions {
  label?: string
  topics: string[]
  onMessage: (event: RealtimeUpdateEvent) => void
  onOpen?: () => void
  onError?: (event: Event) => void
}

/**
 * Minimal Mercure client used by the UI to subscribe to normalized realtime topics.
 */
export class MercureRealtimeClient {
  public constructor(
    private readonly hubPath = '/.well-known/mercure',
  ) {
    console.info('[realtime] Client initialized.', {
      hubPath: this.hubPath,
    })
  }

  public subscribe(options: RealtimeSubscriptionOptions): RealtimeSubscription {
    const subscriptionId = crypto.randomUUID()
    const label = options.label ?? 'anonymous'
    const hubUrl = new URL(this.hubPath, window.location.origin)
    options.topics.forEach((topic) => hubUrl.searchParams.append('topic', topic))

    realtimeDiagnosticsService.registerSubscription(subscriptionId, label)

    console.info('[realtime] Subscription starting.', {
      subscriptionId,
      label,
      topics: options.topics,
      url: hubUrl.toString(),
    })

    const source = new EventSource(hubUrl.toString())
    const handleRealtimeMessage = (message: MessageEvent<string>) => {
      try {
        const event = JSON.parse(message.data) as RealtimeUpdateEvent
        const hydratedEvent = {
          ...event,
          id: message.lastEventId || event.id,
        }

        realtimeDiagnosticsService.logMessage(subscriptionId, label, hydratedEvent)

        options.onMessage(hydratedEvent)
      } catch (error) {
        realtimeDiagnosticsService.updateSubscriptionState(subscriptionId, 'error', this.describeUnknownError(error))

        console.error('[realtime] Message parsing failed.', {
          subscriptionId,
          label,
          error: this.describeUnknownError(error),
          rawData: message.data,
        })
      }
    }

    source.onopen = () => {
      realtimeDiagnosticsService.updateSubscriptionState(subscriptionId, 'open')

      console.info('[realtime] Subscription connected and healthy.', {
        subscriptionId,
        label,
        topics: options.topics,
      })

      options.onOpen?.()
    }

    source.onmessage = handleRealtimeMessage
    for (const eventType of REALTIME_EVENT_TYPES) {
      source.addEventListener(eventType, handleRealtimeMessage as EventListener)
    }

    source.onerror = (event) => {
      const readyState = source.readyState
      const eventError = this.describeEventError(event)
      const errorMessage = readyState === EventSource.CLOSED
        ? `Stream closed by remote endpoint.${eventError ? ` ${eventError}` : ''}`
        : `Stream error; EventSource will retry automatically.${eventError ? ` ${eventError}` : ''}`

      realtimeDiagnosticsService.updateSubscriptionState(
        subscriptionId,
        readyState === EventSource.CLOSED ? 'closed' : 'reconnecting',
        errorMessage,
      )

      if (readyState === EventSource.CLOSED) {
        console.error('[realtime] Subscription closed unexpectedly.', {
          subscriptionId,
          label,
          topics: options.topics,
          readyState,
          reason: errorMessage,
          event,
        })
      } else {
        console.warn('[realtime] Subscription error, reconnecting.', {
          subscriptionId,
          label,
          topics: options.topics,
          readyState,
          reason: errorMessage,
          event,
        })
      }

      options.onError?.(event)
    }

    return {
      close: (reason = 'manual_close') => {
        source.close()
        realtimeDiagnosticsService.removeSubscription(subscriptionId)

        console.info('[realtime] Subscription closed.', {
          subscriptionId,
          label,
          topics: options.topics,
          reason,
        })
      },
    }
  }

  private describeUnknownError(error: unknown): string {
    if (error instanceof Error) {
      return error.message
    }

    return String(error)
  }

  private describeEventError(event: Event): string {
    const errorCandidate = event as Event & {
      message?: unknown
      reason?: unknown
      error?: unknown
      detail?: unknown
      status?: unknown
    }

    const parts = [
      errorCandidate.message,
      errorCandidate.reason,
      errorCandidate.status,
      errorCandidate.detail,
      errorCandidate.error instanceof Error ? errorCandidate.error.message : errorCandidate.error,
    ]
      .map((value) => this.normalizeEventErrorPart(value))
      .filter((value): value is string => value !== null)

    return parts.length > 0 ? `Details: ${parts.join(' | ')}` : ''
  }

  private normalizeEventErrorPart(value: unknown): string | null {
    if (typeof value === 'string') {
      const normalized = value.trim()
      return normalized === '' ? null : normalized
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
      return String(value)
    }

    return null
  }
}

/** Shared Mercure client singleton for the frontend application. */
export const realtimeClient = new MercureRealtimeClient()
