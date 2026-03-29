const LOG_ENDPOINT = '/api/logs/events'
const MAX_STACK_LENGTH = 12_000

type FrontendLogLevel = 'info' | 'warning' | 'error' | 'critical'
type FrontendLogCategory = 'runtime' | 'http' | 'connectivity'
type FrontendLogI18nDomain = 'logs'

interface FrontendLogI18n {
  domain: FrontendLogI18nDomain
  key: string
  parameters?: Record<string, string | number | boolean | null>
}

interface FrontendLogPayload {
  source: 'frontend'
  category: FrontendLogCategory
  level: FrontendLogLevel
  title: string
  message: string
  titleI18n?: FrontendLogI18n
  messageI18n?: FrontendLogI18n
  fingerprint?: string
  origin?: string
  stack?: string
  context?: Record<string, unknown>
  rawPayload?: Record<string, unknown>
}

let installed = false

/**
 * Installs one-time frontend observability hooks so uncaught browser/runtime failures are centralized in backend logs.
 */
export function installFrontendObservability() {
  if (installed || typeof window === 'undefined') {
    return
  }

  installed = true

  window.addEventListener('error', (event) => {
    const error = event.error instanceof Error ? event.error : null

    reportFrontendLog({
      source: 'frontend',
      category: 'runtime',
      level: 'error',
      title: '',
      message: '',
      titleI18n: {
        domain: 'logs',
        key: 'logs.runtime.unexpected_frontend_error.title',
      },
      messageI18n: {
        domain: 'logs',
        key: 'logs.runtime.unexpected_frontend_error.message',
        parameters: {
          '%details%': error?.message || event.message || 'Unknown frontend error',
        },
      },
      fingerprint: buildFingerprint(
        'runtime',
        error?.name || 'ErrorEvent',
        error?.message || event.message || 'unknown',
        typeof event.filename === 'string' ? event.filename : window.location.pathname,
      ),
      origin: readOrigin(event.filename, event.lineno, event.colno),
      stack: trimStack(error?.stack),
      context: {
        pathname: window.location.pathname,
        search: window.location.search,
        online: navigator.onLine,
        userAgent: navigator.userAgent,
      },
      rawPayload: {
        filename: event.filename,
        line: event.lineno,
        column: event.colno,
      },
    })
  })

  window.addEventListener('unhandledrejection', (event) => {
    const reason = event.reason
    const message = extractRejectionMessage(reason)
    const stack = reason instanceof Error ? trimStack(reason.stack) : undefined

    reportFrontendLog({
      source: 'frontend',
      category: 'runtime',
      level: 'error',
      title: '',
      message: '',
      titleI18n: {
        domain: 'logs',
        key: 'logs.runtime.unhandled_rejection.title',
      },
      messageI18n: {
        domain: 'logs',
        key: 'logs.runtime.unhandled_rejection.message',
        parameters: {
          '%details%': message,
        },
      },
      fingerprint: buildFingerprint(
        'runtime',
        reason instanceof Error ? reason.name : 'UnhandledRejection',
        message,
        window.location.pathname,
      ),
      stack,
      context: {
        pathname: window.location.pathname,
        search: window.location.search,
        online: navigator.onLine,
        userAgent: navigator.userAgent,
      },
      rawPayload: {
        reason: serializeUnknown(reason),
      },
    })
  })

  window.addEventListener('offline', () => {
    reportFrontendLog({
      source: 'frontend',
      category: 'connectivity',
      level: 'warning',
      title: '',
      message: '',
      titleI18n: {
        domain: 'logs',
        key: 'logs.connectivity.offline.title',
      },
      messageI18n: {
        domain: 'logs',
        key: 'logs.connectivity.offline.message',
      },
      fingerprint: buildFingerprint('connectivity', 'offline', window.location.pathname),
      context: {
        pathname: window.location.pathname,
        online: navigator.onLine,
      },
    })
  })

  window.addEventListener('online', () => {
    reportFrontendLog({
      source: 'frontend',
      category: 'connectivity',
      level: 'info',
      title: '',
      message: '',
      titleI18n: {
        domain: 'logs',
        key: 'logs.connectivity.online.title',
      },
      messageI18n: {
        domain: 'logs',
        key: 'logs.connectivity.online.message',
      },
      fingerprint: buildFingerprint('connectivity', 'online', window.location.pathname),
      context: {
        pathname: window.location.pathname,
        online: navigator.onLine,
      },
    })
  })
}

/**
 * Reports an HTTP/API failure without routing through the shared Axios client, which would otherwise recurse on itself.
 */
export function reportApiFailure(details: {
  method?: string
  url?: string
  status?: number
  responseMessage?: string
  requestId?: string
}) {
  if (typeof window === 'undefined') {
    return
  }

  const message = details.responseMessage || 'Frontend API request failed'
  const url = details.url || window.location.pathname

  reportFrontendLog({
    source: 'frontend',
    category: 'http',
    level: details.status && details.status >= 500 ? 'error' : 'warning',
    title: '',
    message: '',
    titleI18n: {
      domain: 'logs',
      key: 'logs.http.frontend_api_failure.title',
    },
    messageI18n: {
      domain: 'logs',
      key: 'logs.http.frontend_api_failure.message',
      parameters: {
        '%method%': details.method || 'GET',
        '%url%': url,
        '%status%': details.status ?? 'network',
        '%details%': message,
      },
    },
    fingerprint: buildFingerprint(
      'http',
      String(details.status || 'network'),
      details.method || 'GET',
      url,
      message,
    ),
    origin: url,
    context: {
      method: details.method ?? null,
      url,
      status: details.status ?? null,
      requestId: details.requestId ?? null,
      pathname: window.location.pathname,
      online: navigator.onLine,
    },
  })
}

/**
 * Sends a frontend observability event to the backend without depending on the shared Axios client.
 */
function reportFrontendLog(payload: FrontendLogPayload) {
  const body = JSON.stringify(payload)

  if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
    const ok = navigator.sendBeacon(LOG_ENDPOINT, new Blob([body], { type: 'application/json' }))
    if (ok) {
      return
    }
  }

  void fetch(LOG_ENDPOINT, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body,
    keepalive: true,
  }).catch(() => undefined)
}

/**
 * Normalizes an unhandled rejection reason into a stable human-readable message for logging.
 */
function extractRejectionMessage(reason: unknown): string {
  if (reason instanceof Error) {
    return reason.message
  }

  if (typeof reason === 'string' && reason.trim() !== '') {
    return reason
  }

  return 'Unhandled rejection without usable detail'
}

/**
 * Caps stack traces before persistence so oversized browser payloads do not flood stored logs.
 */
function trimStack(stack?: string | null): string | undefined {
  if (!stack) {
    return undefined
  }

  return stack.length > MAX_STACK_LENGTH ? stack.slice(0, MAX_STACK_LENGTH) : stack
}

/**
 * Builds an origin string compatible with the log event payload from browser filename and position details.
 */
function readOrigin(filename?: string, line?: number, column?: number): string | undefined {
  if (!filename) {
    return undefined
  }

  const suffix = typeof line === 'number' && typeof column === 'number'
    ? `:${line}:${column}`
    : typeof line === 'number'
      ? `:${line}`
      : ''

  return `${filename}${suffix}`
}

/**
 * Serializes arbitrary rejection payloads into a log-friendly structure without throwing during error reporting.
 */
function serializeUnknown(value: unknown): Record<string, unknown> | string | null {
  if (value == null) {
    return null
  }

  if (value instanceof Error) {
    return {
      name: value.name,
      message: value.message,
      stack: trimStack(value.stack),
    }
  }

  if (typeof value === 'object') {
    return value as Record<string, unknown>
  }

  return String(value)
}

/**
 * Produces a short deterministic client-side fingerprint seed for repeated frontend observability events.
 */
function buildFingerprint(...parts: string[]): string {
  return parts
    .map((part) => part.trim().toLowerCase())
    .filter(Boolean)
    .join('|')
    .slice(0, 64)
}
