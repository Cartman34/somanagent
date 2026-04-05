/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import type { RealtimeUpdateEvent } from '@/types'

/** One active realtime subscription. */
export interface RealtimeSubscription {
  close(): void
}

/** Subscription contract for the shared realtime client. */
export interface RealtimeSubscriptionOptions {
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
  ) {}

  public subscribe(options: RealtimeSubscriptionOptions): RealtimeSubscription {
    const hubUrl = new URL(this.hubPath, window.location.origin)
    options.topics.forEach((topic) => hubUrl.searchParams.append('topic', topic))

    const source = new EventSource(hubUrl.toString())

    source.onmessage = (message) => {
      try {
        const event = JSON.parse(message.data) as RealtimeUpdateEvent
        options.onMessage({
          ...event,
          id: message.lastEventId || event.id,
        })
      } catch {
        // Ignore malformed events so one bad payload does not kill the stream consumer.
      }
    }

    if (options.onOpen) {
      source.onopen = options.onOpen
    }

    if (options.onError) {
      source.onerror = options.onError
    }

    return {
      close() {
        source.close()
      },
    }
  }
}

/** Shared Mercure client singleton for the frontend application. */
export const realtimeClient = new MercureRealtimeClient()
