/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { AlertOctagon, ExternalLink } from 'lucide-react'
import { logsApi, type LogFilters } from '@/api/logs'
import { translationsApi } from '@/api/translations'
import type { LogOccurrence, LogEvent } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import EmptyState from '@/components/ui/EmptyState'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'
import Modal from '@/components/ui/Modal'

const PAGE_SIZE = 25
type LogsViewMode = 'occurrences' | 'events'
type LogOccurrenceStatus = LogOccurrence['status']

function fmtDate(date: string) {
  return new Date(date).toLocaleString('fr-FR', {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

function badgeStyle(tone: 'red' | 'orange' | 'blue' | 'gray') {
  const map = {
    red: { background: 'rgba(220, 38, 38, 0.12)', color: '#dc2626' },
    orange: { background: 'rgba(234, 88, 12, 0.12)', color: '#ea580c' },
    blue: { background: 'rgba(37, 99, 235, 0.12)', color: '#2563eb' },
    gray: { background: 'var(--surface2)', color: 'var(--muted)' },
  } as const
  return map[tone]
}

function levelTone(level: string): 'red' | 'orange' | 'blue' | 'gray' {
  if (level === 'critical' || level === 'error') return 'red'
  if (level === 'warning') return 'orange'
  if (level === 'info') return 'blue'
  return 'gray'
}

/**
 * Maps an occurrence triage status to a neutral badge tone for the Logs UI.
 */
function occurrenceStatusTone(status: LogOccurrenceStatus): 'red' | 'orange' | 'blue' | 'gray' {
  if (status === 'resolved') return 'blue'
  if (status === 'acknowledged') return 'orange'
  return 'gray'
}

/**
 * Returns the French UI label associated with an occurrence triage status.
 */
function occurrenceStatusLabel(status: LogOccurrenceStatus) {
  return status === 'acknowledged'
    ? 'vu'
    : status === 'resolved'
      ? 'résolu'
      : status === 'ignored'
        ? 'ignoré'
        : 'ouvert'
}

/**
 * Hides the category badge when it does not add any information beyond the level badge.
 */
function shouldRenderCategoryBadge(level: string, category: string) {
  return category.trim().toLowerCase() !== level.trim().toLowerCase()
}

function readMessengerMeta(context: Record<string, unknown> | null | undefined) {
  const attempt = typeof context?.messenger_attempt === 'number'
    ? context.messenger_attempt
    : typeof context?.messenger_attempt === 'string'
      ? Number(context.messenger_attempt)
      : null
  const retryCount = typeof context?.messenger_retry_count === 'number'
    ? context.messenger_retry_count
    : typeof context?.messenger_retry_count === 'string'
      ? Number(context.messenger_retry_count)
      : null
  const isRetry = context?.messenger_is_retry === true
    || context?.messenger_is_retry === 'true'

  return {
    attempt: Number.isFinite(attempt) ? attempt : null,
    retryCount: Number.isFinite(retryCount) ? retryCount : null,
    isRetry,
    receiver: typeof context?.messenger_receiver === 'string' ? context.messenger_receiver : null,
  }
}

function JsonBlock({ value }: { value: unknown }) {
  if (value == null) return <span style={{ color: 'var(--muted)' }}>Aucune donnée</span>

  return (
    <pre
      className="overflow-x-auto rounded-lg p-3 text-xs"
      style={{ background: 'var(--surface2)', color: 'var(--text)', border: '1px solid var(--border)' }}
    >
      {JSON.stringify(value, null, 2)}
    </pre>
  )
}

/**
 * Renders the triage state of an occurrence in a compact reusable badge.
 */
function OccurrenceStatusBadge({ status }: { status: LogOccurrenceStatus }) {
  return (
    <span className="rounded-full px-2 py-1 text-xs" style={badgeStyle(occurrenceStatusTone(status))}>
      {occurrenceStatusLabel(status)}
    </span>
  )
}

function EntityLinks({ occurrence }: { occurrence: LogOccurrence }) {
  return (
    <div className="flex flex-wrap gap-2 text-xs">
      {occurrence.projectId && (
        <Link
          to={`/projects/${occurrence.projectId}`}
          className="inline-flex items-center gap-1 rounded-full px-2 py-1"
          style={{ background: 'var(--surface2)', color: 'var(--text)' }}
        >
          Projet
          <ExternalLink className="h-3 w-3" />
        </Link>
      )}
      {occurrence.taskId && occurrence.projectId && (
        <Link
          to={`/projects/${occurrence.projectId}?tab=board&task=${occurrence.taskId}`}
          className="inline-flex items-center gap-1 rounded-full px-2 py-1"
          style={{ background: 'var(--surface2)', color: 'var(--text)' }}
        >
          Tâche
          <ExternalLink className="h-3 w-3" />
        </Link>
      )}
      {occurrence.agentId && (
        <Link
          to={occurrence.projectId ? `/projects/${occurrence.projectId}?tab=team&agent=${occurrence.agentId}` : '/agents'}
          className="inline-flex items-center gap-1 rounded-full px-2 py-1"
          style={{ background: 'var(--surface2)', color: 'var(--text)' }}
        >
          Agent
          <ExternalLink className="h-3 w-3" />
        </Link>
      )}
    </div>
  )
}

function EventCard({ event }: { event: LogEvent }) {
  const messenger = readMessengerMeta(event.context)

  return (
    <div className="rounded-xl border p-4" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
      <div className="flex flex-wrap items-center gap-2">
        <span className="rounded-full px-2 py-1 text-xs font-medium" style={badgeStyle(levelTone(event.level))}>
          {event.level}
        </span>
        {shouldRenderCategoryBadge(event.level, event.category) && (
          <span className="rounded-full px-2 py-1 text-xs" style={badgeStyle(event.category === 'error' ? 'red' : 'blue')}>
            {event.category}
          </span>
        )}
        {messenger.attempt !== null && (
          <span className="rounded-full px-2 py-1 text-xs" style={badgeStyle(messenger.isRetry ? 'orange' : 'gray')}>
            tentative {messenger.attempt}
          </span>
        )}
        {messenger.isRetry && (
          <span className="rounded-full px-2 py-1 text-xs" style={badgeStyle('orange')}>
            retry
          </span>
        )}
        <span className="text-xs" style={{ color: 'var(--muted)' }}>{event.source}</span>
        <span className="ml-auto text-xs" style={{ color: 'var(--muted)' }}>{fmtDate(event.occurredAt)}</span>
      </div>

      <div className="mt-3 space-y-2">
        <p className="text-sm font-semibold" style={{ color: 'var(--text)' }}>{event.title}</p>
        <p className="text-sm whitespace-pre-wrap" style={{ color: 'var(--text)' }}>{event.message}</p>
      </div>

      <div className="mt-3 grid gap-3 lg:grid-cols-2">
        <div className="space-y-2">
          <p className="text-xs font-medium uppercase tracking-wide" style={{ color: 'var(--muted)' }}>Contexte</p>
          <JsonBlock value={event.context} />
        </div>
        <div className="space-y-2">
          <p className="text-xs font-medium uppercase tracking-wide" style={{ color: 'var(--muted)' }}>Références</p>
          <JsonBlock value={{
            projectId: event.projectId,
            taskId: event.taskId,
            agentId: event.agentId,
            exchangeRef: event.exchangeRef,
            requestRef: event.requestRef,
            traceRef: event.traceRef,
            origin: event.origin,
          }} />
        </div>
      </div>

      {event.stack && (
        <div className="mt-3 space-y-2">
          <p className="text-xs font-medium uppercase tracking-wide" style={{ color: 'var(--muted)' }}>Stack</p>
          <pre
            className="overflow-x-auto rounded-lg p-3 text-xs whitespace-pre-wrap"
            style={{ background: 'var(--surface)', color: 'var(--text)', border: '1px solid var(--border)' }}
          >
            {event.stack}
          </pre>
        </div>
      )}
    </div>
  )
}

function OccurrenceDetail({ occurrenceId }: { occurrenceId: string }) {
  const qc = useQueryClient()
  const { data, isLoading, error } = useQuery({
    queryKey: ['log-occurrence', occurrenceId],
    queryFn: () => logsApi.getOccurrence(occurrenceId),
  })

  const statusMutation = useMutation({
    mutationFn: (status: LogOccurrenceStatus) => logsApi.updateOccurrenceStatus(occurrenceId, { status }),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: ['logs'] })
      await qc.invalidateQueries({ queryKey: ['log-occurrence', occurrenceId] })
    },
  })

  if (isLoading) return <PageSpinner />
  if (error) return <ErrorMessage message={(error as Error).message} />
  if (!data) return null

  const occurrenceMessenger = readMessengerMeta(data.occurrence.contextSnapshot)

  return (
    <div className="flex h-full min-h-0 flex-col gap-4">
      <div className="shrink-0 rounded-xl border p-4" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
        <div className="flex flex-wrap items-center gap-2">
          <span className="rounded-full px-2 py-1 text-xs font-medium" style={badgeStyle(levelTone(data.occurrence.level))}>
            {data.occurrence.level}
          </span>
          {shouldRenderCategoryBadge(data.occurrence.level, data.occurrence.category) && (
            <span className="rounded-full px-2 py-1 text-xs" style={badgeStyle(data.occurrence.category === 'error' ? 'red' : 'blue')}>
              {data.occurrence.category}
            </span>
          )}
          <OccurrenceStatusBadge status={data.occurrence.status} />
          <span className="text-xs" style={{ color: 'var(--muted)' }}>{data.occurrence.source}</span>
          <span className="ml-auto text-xs" style={{ color: 'var(--muted)' }}>
            {data.occurrence.occurrenceCount} occurrence{data.occurrence.occurrenceCount > 1 ? 's' : ''}
          </span>
        </div>

        <div className="mt-3 space-y-2">
          <p className="text-base font-semibold" style={{ color: 'var(--text)' }}>{data.occurrence.title}</p>
          <p className="text-sm whitespace-pre-wrap" style={{ color: 'var(--text)' }}>{data.occurrence.message}</p>
        </div>

        <div className="mt-4 grid gap-3 lg:grid-cols-2">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide" style={{ color: 'var(--muted)' }}>Fenêtre</p>
            <p className="mt-1 text-sm" style={{ color: 'var(--text)' }}>
              {fmtDate(data.occurrence.firstSeenAt)} → {fmtDate(data.occurrence.lastSeenAt)}
            </p>
            {occurrenceMessenger.attempt !== null && (
              <p className="mt-2 text-sm" style={{ color: 'var(--text)' }}>
                Dernière tentative connue : {occurrenceMessenger.attempt}
                {occurrenceMessenger.isRetry ? ' (retry)' : ' (premier passage)'}
              </p>
            )}
          </div>
          <div>
            <p className="text-xs font-medium uppercase tracking-wide" style={{ color: 'var(--muted)' }}>Navigation</p>
            <div className="mt-2">
              <EntityLinks occurrence={data.occurrence} />
            </div>
          </div>
        </div>

        <div className="mt-4 flex flex-wrap gap-2">
          <button
            onClick={() => statusMutation.mutate('acknowledged')}
            disabled={statusMutation.isPending || data.occurrence.status === 'acknowledged'}
            className="btn-secondary disabled:opacity-40"
          >
            Marquer comme vu
          </button>
          <button
            onClick={() => statusMutation.mutate('resolved')}
            disabled={statusMutation.isPending || data.occurrence.status === 'resolved'}
            className="btn-primary disabled:opacity-40"
          >
            Marquer comme résolu
          </button>
          {data.occurrence.status !== 'open' && (
            <button
              onClick={() => statusMutation.mutate('open')}
              disabled={statusMutation.isPending}
              className="btn-secondary disabled:opacity-40"
            >
              Réouvrir
            </button>
          )}
        </div>

        <div className="mt-4 space-y-2">
          <p className="text-xs font-medium uppercase tracking-wide" style={{ color: 'var(--muted)' }}>Contexte agrégé</p>
          <JsonBlock value={data.occurrence.contextSnapshot} />
        </div>
      </div>

      <div className="min-h-0 flex-1 space-y-3 overflow-y-auto pr-1">
        {data.events.map((event) => (
          <EventCard key={event.id} event={event} />
        ))}
      </div>
    </div>
  )
}

export default function LogsPage() {
  const qc = useQueryClient()
  const [viewMode, setViewMode] = useState<LogsViewMode>('occurrences')
  const [page, setPage] = useState(1)
  const [source, setSource] = useState('')
  const [category, setCategory] = useState('')
  const [level, setLevel] = useState('')
  const [status, setStatus] = useState('open')
  const [projectId, setProjectId] = useState('')
  const [taskId, setTaskId] = useState('')
  const [agentId, setAgentId] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [selectedOccurrenceId, setSelectedOccurrenceId] = useState<string | null>(null)

  const filters = useMemo<LogFilters>(() => ({
    page,
    limit: PAGE_SIZE,
    source: source || undefined,
    category: category || undefined,
    level: level || undefined,
    status: status || undefined,
    projectId: projectId || undefined,
    taskId: taskId || undefined,
    agentId: agentId || undefined,
    from: from || undefined,
    to: to || undefined,
  }), [agentId, category, from, level, page, projectId, source, status, taskId, to])

  const occurrencesQuery = useQuery({
    queryKey: ['logs', 'occurrences', filters],
    queryFn: () => logsApi.listOccurrences(filters),
    enabled: viewMode === 'occurrences',
  })
  const eventsQuery = useQuery({
    queryKey: ['logs', 'events', filters],
    queryFn: () => logsApi.listEvents(filters),
    enabled: viewMode === 'events',
  })

  const activeQuery = viewMode === 'occurrences' ? occurrencesQuery : eventsQuery
  const activeIsFetching = occurrencesQuery.isFetching || eventsQuery.isFetching

  const { data: logsI18n } = useQuery({
    queryKey: ['ui-translations', 'logs'],
    queryFn: () => translationsApi.list(['log.list.loading', 'common.action.refresh']),
  })
  const tt = (key: string) => logsI18n?.translations[key] ?? key
  const occurrences = occurrencesQuery.data?.data ?? []
  const events = eventsQuery.data?.data ?? []
  const total = activeQuery.data?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE))

  if (activeQuery.isLoading) return <PageSpinner />
  if (activeQuery.error) return <ErrorMessage message={(activeQuery.error as Error).message} onRetry={() => activeQuery.refetch()} />

  return (
    <>
      <PageHeader
        title="Logs"
        description={viewMode === 'occurrences'
          ? "Diagnostic agrégé des warnings et erreurs récurrents."
          : "Explorateur d'événements bruts pour suivre la chronologie exacte des signaux."}
        onRefresh={() => { qc.invalidateQueries({ queryKey: ['logs'] }) }}
        refreshTitle={tt('common.action.refresh')}
        action={(
          <div className="inline-flex rounded-xl border p-1" style={{ borderColor: 'var(--border)', background: 'var(--surface)' }}>
            <button
              onClick={() => { setPage(1); setViewMode('occurrences') }}
              className="rounded-lg px-3 py-1.5 text-sm"
              style={viewMode === 'occurrences'
                ? { background: 'var(--brand)', color: '#fff' }
                : { color: 'var(--text)' }}
            >
              Occurrences
            </button>
            <button
              onClick={() => { setPage(1); setViewMode('events') }}
              className="rounded-lg px-3 py-1.5 text-sm"
              style={viewMode === 'events'
                ? { background: 'var(--brand)', color: '#fff' }
                : { color: 'var(--text)' }}
            >
              Événements
            </button>
          </div>
        )}
      />

      <div className="relative">
        <ContentLoadingOverlay isLoading={activeIsFetching && !activeQuery.isLoading} label={tt('log.list.loading')} />

        <div className="mb-6 grid gap-3 rounded-2xl border p-4 md:grid-cols-3 xl:grid-cols-6" style={{ borderColor: 'var(--border)', background: 'var(--surface)' }}>
        <select className="input" value={source} onChange={(e) => { setPage(1); setSource(e.target.value) }}>
          <option value="">Toutes les sources</option>
          <option value="backend">backend</option>
          <option value="worker">worker</option>
          <option value="frontend">frontend</option>
          <option value="infra">infra</option>
        </select>
        <select className="input" value={category} onChange={(e) => { setPage(1); setCategory(e.target.value) }}>
          <option value="">Toutes les catégories</option>
          <option value="error">error</option>
          <option value="runtime">runtime</option>
          <option value="http">http</option>
          <option value="connectivity">connectivity</option>
          <option value="health">health</option>
          <option value="auth">auth</option>
        </select>
        <select className="input" value={level} onChange={(e) => { setPage(1); setLevel(e.target.value) }}>
          <option value="">Tous les niveaux</option>
          <option value="error">error</option>
          <option value="critical">critical</option>
          <option value="warning">warning</option>
          <option value="info">info</option>
        </select>
        {viewMode === 'occurrences' ? (
          <select className="input" value={status} onChange={(e) => { setPage(1); setStatus(e.target.value) }}>
            <option value="">Tous les statuts</option>
            <option value="open">open</option>
            <option value="acknowledged">acknowledged</option>
            <option value="resolved">resolved</option>
            <option value="ignored">ignored</option>
          </select>
        ) : (
          <div className="input flex items-center text-sm" style={{ color: 'var(--muted)' }}>
            Les événements bruts n’ont pas de statut d’occurrence.
          </div>
        )}
        <input className="input" placeholder="Project ID" value={projectId} onChange={(e) => { setPage(1); setProjectId(e.target.value) }} />
        <input className="input" placeholder="Task ID" value={taskId} onChange={(e) => { setPage(1); setTaskId(e.target.value) }} />
        <input className="input" placeholder="Agent ID" value={agentId} onChange={(e) => { setPage(1); setAgentId(e.target.value) }} />
        <input className="input" type="datetime-local" value={from} onChange={(e) => { setPage(1); setFrom(e.target.value) }} />
        <input className="input" type="datetime-local" value={to} onChange={(e) => { setPage(1); setTo(e.target.value) }} />
      </div>

      {viewMode === 'occurrences' && occurrences.length === 0 ? (
        <EmptyState
          icon={AlertOctagon}
          title="Aucune occurrence"
          description="Aucun log agrégé ne correspond aux filtres courants."
        />
      ) : viewMode === 'events' && events.length === 0 ? (
        <EmptyState
          icon={AlertOctagon}
          title="Aucun événement"
          description="Aucun événement brut ne correspond aux filtres courants."
        />
      ) : viewMode === 'occurrences' ? (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead style={{ background: 'var(--surface2)', borderBottom: '1px solid var(--border)' }}>
                <tr>
                  <th className="px-4 py-3 text-left font-medium" style={{ color: 'var(--muted)' }}>Titre</th>
                  <th className="px-4 py-3 text-left font-medium" style={{ color: 'var(--muted)' }}>Niveau</th>
                  <th className="px-4 py-3 text-left font-medium" style={{ color: 'var(--muted)' }}>Source</th>
                  <th className="px-4 py-3 text-left font-medium" style={{ color: 'var(--muted)' }}>Occurrences</th>
                  <th className="px-4 py-3 text-left font-medium" style={{ color: 'var(--muted)' }}>Dernière fois</th>
                  <th className="px-4 py-3 text-left font-medium" style={{ color: 'var(--muted)' }}>Contexte</th>
                </tr>
              </thead>
              <tbody>
                {occurrences.map((occurrence) => (
                  <tr
                    key={occurrence.id}
                    className="cursor-pointer border-b transition-colors hover:opacity-90"
                    style={{ borderColor: 'var(--border)' }}
                    onClick={() => setSelectedOccurrenceId(occurrence.id)}
                  >
                    <td className="px-4 py-3 align-top">
                      <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="font-medium" style={{ color: 'var(--text)' }}>{occurrence.title}</p>
                          <OccurrenceStatusBadge status={occurrence.status} />
                        </div>
                        <p className="line-clamp-2 text-xs" style={{ color: 'var(--muted)' }}>{occurrence.message}</p>
                      </div>
                    </td>
                    <td className="px-4 py-3 align-top">
                      <div className="flex flex-wrap gap-2">
                        <span className="rounded-full px-2 py-1 text-xs font-medium" style={badgeStyle(levelTone(occurrence.level))}>
                          {occurrence.level}
                        </span>
                        {shouldRenderCategoryBadge(occurrence.level, occurrence.category) && (
                          <span className="rounded-full px-2 py-1 text-xs" style={badgeStyle(occurrence.category === 'error' ? 'red' : 'blue')}>
                            {occurrence.category}
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="px-4 py-3 align-top" style={{ color: 'var(--text)' }}>{occurrence.source}</td>
                    <td className="px-4 py-3 align-top" style={{ color: 'var(--text)' }}>{occurrence.occurrenceCount}</td>
                    <td className="px-4 py-3 align-top whitespace-nowrap" style={{ color: 'var(--muted)' }}>{fmtDate(occurrence.lastSeenAt)}</td>
                    <td className="px-4 py-3 align-top">
                      <EntityLinks occurrence={occurrence} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {totalPages > 1 && (
            <div className="flex items-center justify-between px-4 py-3" style={{ borderTop: '1px solid var(--border)' }}>
              <p className="text-xs" style={{ color: 'var(--muted)' }}>
                {(page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, total)} sur {total} occurrences
              </p>
              <div className="flex gap-2">
                <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1} className="btn-secondary disabled:opacity-40">Précédent</button>
                <button onClick={() => setPage((p) => Math.min(totalPages, p + 1))} disabled={page === totalPages} className="btn-secondary disabled:opacity-40">Suivant</button>
              </div>
            </div>
          )}
        </div>
      ) : (
        <div className="space-y-3">
          {events.map((event) => (
            <EventCard key={event.id} event={event} />
          ))}

          {totalPages > 1 && (
            <div className="flex items-center justify-between rounded-2xl border px-4 py-3" style={{ borderColor: 'var(--border)', background: 'var(--surface)' }}>
              <p className="text-xs" style={{ color: 'var(--muted)' }}>
                {(page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, total)} sur {total} événements
              </p>
              <div className="flex gap-2">
                <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1} className="btn-secondary disabled:opacity-40">Précédent</button>
                <button onClick={() => setPage((p) => Math.min(totalPages, p + 1))} disabled={page === totalPages} className="btn-secondary disabled:opacity-40">Suivant</button>
              </div>
            </div>
          )}
        </div>
      )}
      </div>

      <Modal
        open={selectedOccurrenceId !== null}
        onClose={() => setSelectedOccurrenceId(null)}
        title="Détail d'occurrence"
        size="2xl"
      >
        {selectedOccurrenceId ? <OccurrenceDetail occurrenceId={selectedOccurrenceId} /> : null}
      </Modal>
    </>
  )
}
