/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import type { AgentTaskExecutionResourceSnapshot } from '@/types'

/** Translated labels required to render one execution resource snapshot. */
export interface ExecutionResourceSnapshotLabels {
  title: string
  capturedAt: string
  agent: string
  skill: string
  prompt: string
  scope: string
  source: string
  filePath: string
  connector: string
  model: string
  role: string
  originalSource: string
  content: string
  instruction: string
  context: string
  renderedPrompt: string
  taskActions: string
  ticketTransitions: string
  allowedEffects: string
  notAvailable: string
  noAgentFile: string
}

function formatJson(value: unknown): string {
  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return String(value)
  }
}

/**
 * Renders the immutable runtime resource snapshot captured for one agent execution.
 */
export default function ExecutionResourceSnapshot({
  snapshot,
  labels,
  formatDateTime,
}: {
  snapshot: AgentTaskExecutionResourceSnapshot | null | undefined
  labels: ExecutionResourceSnapshotLabels
  formatDateTime: (value: string) => string
}) {
  if (!snapshot) {
    return null
  }

  return (
    <div className="mt-3 rounded-lg border p-3 text-xs" style={{ borderColor: 'var(--border)', background: 'var(--surface)' }}>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
        <span className="font-semibold" style={{ color: 'var(--text)' }}>{labels.title}</span>
        <span style={{ color: 'var(--muted)' }}>
          {labels.capturedAt}: {formatDateTime(snapshot.capturedAt)}
        </span>
      </div>

      <div className="mt-3 space-y-2">
        <details>
          <summary className="cursor-pointer font-medium" style={{ color: 'var(--text)' }}>{labels.agent}</summary>
          <div className="mt-2 grid gap-2 md:grid-cols-2" style={{ color: 'var(--muted)' }}>
            <div>{labels.source}: {snapshot.agent.resourceKind}</div>
            <div>{labels.connector}: {snapshot.agent.connector}</div>
            <div>{labels.model}: {snapshot.agent.config.model}</div>
            <div>{labels.role}: {snapshot.agent.role?.name ?? labels.notAvailable}</div>
            <div>{labels.filePath}: {snapshot.agent.filePath ?? labels.notAvailable}</div>
            <div>{labels.noAgentFile}: {snapshot.limits.agentFilePathReason}</div>
          </div>
          <pre className="mt-2 whitespace-pre-wrap break-words rounded p-2 text-[11px]" style={{ background: 'var(--surface2)', color: 'var(--muted)' }}>
            {formatJson(snapshot.agent)}
          </pre>
        </details>

        <details>
          <summary className="cursor-pointer font-medium" style={{ color: 'var(--text)' }}>{labels.skill}</summary>
          <div className="mt-2 grid gap-2 md:grid-cols-2" style={{ color: 'var(--muted)' }}>
            <div>{labels.source}: {snapshot.skill.source}</div>
            <div>{labels.originalSource}: {snapshot.skill.originalSource ?? labels.notAvailable}</div>
            <div>{labels.filePath}: {snapshot.skill.filePath}</div>
          </div>
          <div className="mt-2">
            <div className="mb-1 font-medium" style={{ color: 'var(--text)' }}>{labels.content}</div>
            <pre className="whitespace-pre-wrap break-words rounded p-2 text-[11px]" style={{ background: 'var(--surface2)', color: 'var(--muted)' }}>
              {snapshot.skill.content}
            </pre>
          </div>
        </details>

        <details>
          <summary className="cursor-pointer font-medium" style={{ color: 'var(--text)' }}>{labels.prompt}</summary>
          <div className="mt-2">
            <div className="mb-1 font-medium" style={{ color: 'var(--text)' }}>{labels.instruction}</div>
            <pre className="whitespace-pre-wrap break-words rounded p-2 text-[11px]" style={{ background: 'var(--surface2)', color: 'var(--muted)' }}>
              {snapshot.prompt.instruction}
            </pre>
          </div>
          <div className="mt-2">
            <div className="mb-1 font-medium" style={{ color: 'var(--text)' }}>{labels.context}</div>
            <pre className="whitespace-pre-wrap break-words rounded p-2 text-[11px]" style={{ background: 'var(--surface2)', color: 'var(--muted)' }}>
              {formatJson(snapshot.prompt.context)}
            </pre>
          </div>
          <div className="mt-2">
            <div className="mb-1 font-medium" style={{ color: 'var(--text)' }}>{labels.renderedPrompt}</div>
            <pre className="whitespace-pre-wrap break-words rounded p-2 text-[11px]" style={{ background: 'var(--surface2)', color: 'var(--muted)' }}>
              {snapshot.prompt.rendered}
            </pre>
          </div>
        </details>

        <details>
          <summary className="cursor-pointer font-medium" style={{ color: 'var(--text)' }}>{labels.scope}</summary>
          <div className="mt-2">
            <div className="mb-1 font-medium" style={{ color: 'var(--text)' }}>{labels.allowedEffects}</div>
            <pre className="whitespace-pre-wrap break-words rounded p-2 text-[11px]" style={{ background: 'var(--surface2)', color: 'var(--muted)' }}>
              {snapshot.scope.allowedEffects.join('\n')}
            </pre>
          </div>
          <div className="mt-2">
            <div className="mb-1 font-medium" style={{ color: 'var(--text)' }}>{labels.taskActions}</div>
            <pre className="whitespace-pre-wrap break-words rounded p-2 text-[11px]" style={{ background: 'var(--surface2)', color: 'var(--muted)' }}>
              {formatJson(snapshot.scope.taskActions)}
            </pre>
          </div>
          <div className="mt-2">
            <div className="mb-1 font-medium" style={{ color: 'var(--text)' }}>{labels.ticketTransitions}</div>
            <pre className="whitespace-pre-wrap break-words rounded p-2 text-[11px]" style={{ background: 'var(--surface2)', color: 'var(--muted)' }}>
              {formatJson(snapshot.scope.ticketTransitions)}
            </pre>
          </div>
        </details>
      </div>
    </div>
  )
}
