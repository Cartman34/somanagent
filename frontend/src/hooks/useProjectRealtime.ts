/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { realtimeClient } from '@/lib/realtime/client'
import { realtimeTopics } from '@/lib/realtime/topics'
import type { RealtimeUpdateEvent } from '@/types'

/**
 * Subscribes the project detail page to backend SSE events and invalidates affected caches.
 */
export function useProjectRealtime(projectId: string | undefined, openEntityId: string | null): void {
  const qc = useQueryClient()

  useEffect(() => {
    if (!projectId) {
      return undefined
    }

    const topics = [
      realtimeTopics.project(projectId),
      realtimeTopics.projectTickets(projectId),
      realtimeTopics.projectAudit(projectId),
      realtimeTopics.projectTokens(projectId),
    ]

    if (openEntityId !== null) {
      topics.push(realtimeTopics.ticket(projectId, openEntityId))
      topics.push(realtimeTopics.task(projectId, openEntityId))
    }

    const subscription = realtimeClient.subscribe({
      topics,
      onMessage: (event: RealtimeUpdateEvent) => {
      try {
        const payload = event.payload ?? {}
        const relatedIds = [
          payload.ticketId,
          payload.taskId,
          ...(Array.isArray(payload.taskIds) ? payload.taskIds : []),
        ].filter((value): value is string => typeof value === 'string' && value !== '')

        switch (event.type) {
          case 'project.changed':
            qc.invalidateQueries({ queryKey: ['projects', projectId] })
            qc.invalidateQueries({ queryKey: ['tickets', projectId] })
            qc.invalidateQueries({ queryKey: ['project-audit', projectId] })
            qc.invalidateQueries({ queryKey: ['project-tokens', projectId] })
            return

          case 'ticket.changed':
          case 'ticket.deleted':
          case 'task.changed':
          case 'task.deleted':
            qc.invalidateQueries({ queryKey: ['tickets', projectId] })
            qc.invalidateQueries({ queryKey: ['project-audit', projectId] })
            break

          case 'ticket.log.changed':
            qc.invalidateQueries({ queryKey: ['tickets', projectId] })
            break

          case 'execution.changed':
            qc.invalidateQueries({ queryKey: ['tickets', projectId] })
            qc.invalidateQueries({ queryKey: ['project-tokens', projectId] })
            break

          default:
            return
        }

        if (openEntityId !== null && relatedIds.includes(openEntityId)) {
          qc.invalidateQueries({ queryKey: ['task-detail', openEntityId] })
        }

        if (event.type === 'ticket.changed' && openEntityId !== null && payload.ticketId === openEntityId) {
          qc.invalidateQueries({ queryKey: ['task-detail', openEntityId] })
        }
      } catch {
        // Ignore malformed event payloads so the subscription stays alive.
      }
      },
    })

    return () => {
      subscription.close()
    }
  }, [openEntityId, projectId, qc])
}
