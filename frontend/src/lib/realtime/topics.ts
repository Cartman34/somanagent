/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

const REALTIME_TOPIC_BASE = 'https://somanagent.local/realtime'

function projectTopic(projectId: string): string {
  return `${REALTIME_TOPIC_BASE}/projects/${projectId}`
}

function projectTicketsTopic(projectId: string): string {
  return `${projectTopic(projectId)}/tickets`
}

/** Canonical Mercure topics shared by frontend subscriptions. */
export const realtimeTopics = {
  project(projectId: string): string {
    return projectTopic(projectId)
  },

  projectTickets(projectId: string): string {
    return projectTicketsTopic(projectId)
  },

  ticket(projectId: string, ticketId: string): string {
    return `${projectTicketsTopic(projectId)}/${ticketId}`
  },

  task(projectId: string, taskId: string): string {
    return `${projectTopic(projectId)}/tasks/${taskId}`
  },

  projectAudit(projectId: string): string {
    return `${projectTopic(projectId)}/audit`
  },

  projectTokens(projectId: string): string {
    return `${projectTopic(projectId)}/tokens`
  },
}
