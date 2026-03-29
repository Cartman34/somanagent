const LOG_ENDPOINT = '/api/logs/events'
const MAX_STACK_LENGTH = 12_000

type FrontendLogLevel = 'info' | 'warning' | 'error' | 'critical'
type FrontendLogCategory = 'runtime' | 'http' | 'connectivity'

interface FrontendLogPayload {
  source: 'frontend'
  category: FrontendLogCategory
  level: FrontendLogLevel
  title: string
  message: string
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
      // Stored in DB for the in-app log UI, so the human-facing message stays in French.
      title: 'Erreur frontend non interceptée',
      // Stored in DB for the in-app log UI, so the human-facing message stays in French.
      message: error?.message || event.message || 'Erreur frontend non interceptée',
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
      // Stored in DB for the in-app log UI, so the human-facing message stays in French.
      title: 'Promesse rejetée sans gestionnaire',
      message,
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
      // Stored in DB for the in-app log UI, so the human-facing message stays in French.
      title: 'Navigateur hors ligne',
      // Stored in DB for the in-app log UI, so the human-facing message stays in French.
      message: 'Le navigateur a perdu sa connectivité réseau.',
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
      // Stored in DB for the in-app log UI, so the human-facing message stays in French.
      title: 'Navigateur reconnecté',
      // Stored in DB for the in-app log UI, so the human-facing message stays in French.
      message: 'Le navigateur a retrouvé sa connectivité réseau après une coupure.',
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

  // Stored in DB for the in-app log UI, so the human-facing message stays in French.
  const message = details.responseMessage || 'Échec d’appel API frontend'
  const url = details.url || window.location.pathname

  reportFrontendLog({
    source: 'frontend',
    category: 'http',
    level: details.status && details.status >= 500 ? 'error' : 'warning',
    // Stored in DB for the in-app log UI, so the human-facing message stays in French.
    title: 'Échec d’appel API frontend',
    message,
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

function extractRejectionMessage(reason: unknown): string {
  if (reason instanceof Error) {
    return reason.message
  }

  if (typeof reason === 'string' && reason.trim() !== '') {
    return reason
  }

  // Stored in DB for the in-app log UI, so the human-facing message stays in French.
  return 'Promesse rejetée sans détail exploitable'
}

function trimStack(stack?: string | null): string | undefined {
  if (!stack) {
    return undefined
  }

  return stack.length > MAX_STACK_LENGTH ? stack.slice(0, MAX_STACK_LENGTH) : stack
}

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

function buildFingerprint(...parts: string[]): string {
  return parts
    .map((part) => part.trim().toLowerCase())
    .filter(Boolean)
    .join('|')
    .slice(0, 64)
}
