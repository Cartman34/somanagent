/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useEffect, useState } from 'react'
import { useParams, Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Plus } from 'lucide-react'
import { projectsApi } from '@/api/projects'
import { workflowsApi } from '@/api/workflows'
import { ticketsApi, ticketTasksApi, type ProjectRequestPayload, type ProjectRequestResult, type TicketTaskPayload } from '@/api/tickets'
import type { Ticket, TicketTask, Workflow, Module } from '@/types'
import { PageSpinner } from '@/components/ui/Spinner'
import ErrorMessage from '@/components/ui/ErrorMessage'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'
import AgentSheet from '@/components/project/AgentSheet'
import TaskForm from '@/components/project/TaskForm'
import RequestForm from '@/components/project/RequestForm'
import TaskDrawer from '@/components/project/TaskDrawer'
import ProjectGeneralTab from '@/components/project/ProjectGeneralTab'
import ProjectBoardTab from '@/components/project/ProjectBoardTab'
import ProjectTasksTab from '@/components/project/ProjectTasksTab'
import ProjectTeamTab from '@/components/project/ProjectTeamTab'
import ProjectModulesTab from '@/components/project/ProjectModulesTab'
import ProjectAuditTab from '@/components/project/ProjectAuditTab'
import ProjectTokensTab from '@/components/project/ProjectTokensTab'
import { useProjectRealtime } from '@/hooks/useProjectRealtime'
import { useTranslation } from '@/hooks/useTranslation'
import { useToast } from '@/hooks/useToast'
import {
  isTicket,
  Tab,
  TABS,
  DEFAULT_TAB,
  isProjectTab,
} from '@/lib/project/constants'

// ─── Page-level translation keys ─────────────────────────────────────────────

const PROJECT_DETAIL_TRANSLATION_KEYS = [
  'common.action.refresh',
  'project.detail.back_link',
  'project.detail.create_request',
  'project.detail.create_task',
  'project.detail.create_technical_task',
  'project.detail.delete_message',
  'project.detail.not_found',
  'project.detail.stats.modules',
  'project.detail.stats.stories_bugs',
  'project.detail.stats.tasks',
  'project.detail.stats.team',
  'project.detail.tabs.audit',
  'project.detail.tabs.board',
  'project.detail.tabs.general',
  'project.detail.tabs.modules',
  'project.detail.tabs.tasks',
  'project.detail.tabs.team',
  'project.detail.tabs.tokens',
  'project.detail.ticket_id_required',
  'project.detail.transition_impossible',
  'project.item.loading',
  'project.progress.error.request_creation_failed',
  'project.progress.banner',
  'project.progress.blocked_reason',
  'project.progress.rework_title',
  'toast.request_submitted',
  'toast.ticket_created',
  'toast.ticket_deleted',
] as const

const PROJECT_TAB_LABEL_KEYS: Record<Tab, string> = {
  general: 'project.detail.tabs.general',
  board: 'project.detail.tabs.board',
  tasks: 'project.detail.tabs.tasks',
  team: 'project.detail.tabs.team',
  modules: 'project.detail.tabs.modules',
  audit: 'project.detail.tabs.audit',
  tokens: 'project.detail.tabs.tokens',
}

// ─── Main page ────────────────────────────────────────────────────────────────

// Re-export Tab type so consumers can import it from this module if needed
export type { Tab }

/**
 * Project detail hub page with multiple tabs: General, Board, Tasks, Team, Modules, Audit, Tokens.
 * Stories/bugs kanban and technical tasks are accessible directly from this page,
 * scoped to the project — no need to navigate to a global Tasks page.
 */
export default function ProjectDetailPage() {
  const { id } = useParams<{ id: string }>()
  const [searchParams, setSearchParams] = useSearchParams()
  const qc = useQueryClient()

  const { t } = useTranslation(PROJECT_DETAIL_TRANSLATION_KEYS)
  const { toast } = useToast()

  const [tab, setTab]                         = useState<Tab>(() => {
    const requestedTab = searchParams.get('tab')
    return isProjectTab(requestedTab) ? requestedTab : DEFAULT_TAB
  })
  const [createOpen, setCreateOpen]           = useState(false)
  const [deleteTask, setDeleteTask]           = useState<Ticket | TicketTask | null>(null)
  const [transitionError, setTransitionError] = useState<string | null>(null)
  const [pendingTaskId, setPendingTaskId]     = useState<string | null>(null)
  const [requestDispatchError, setRequestDispatchError] = useState<string | null>(null)
  const [drawerTaskId, setDrawerTaskId]       = useState<string | null>(null)
  const [agentSheetId, setAgentSheetId]       = useState<string | null>(null)
  const drawerSize = searchParams.get('drawer')
  const isTaskDrawerExpanded = drawerSize === 'expanded'

  useEffect(() => {
    const requestedTab = searchParams.get('tab')
    const nextTab = isProjectTab(requestedTab) ? requestedTab : DEFAULT_TAB

    if (tab !== nextTab) {
      setTab(nextTab)
      return
    }

    if (!isProjectTab(requestedTab)) {
      const nextParams = new URLSearchParams(searchParams)
      nextParams.set('tab', nextTab)
      setSearchParams(nextParams, { replace: true })
    }
  }, [searchParams, setSearchParams, tab])

  useEffect(() => {
    const requestedTaskId = searchParams.get('task')
    if (requestedTaskId && requestedTaskId !== drawerTaskId) {
      setDrawerTaskId(requestedTaskId)
      return
    }

    if (!requestedTaskId && drawerTaskId !== null) {
      setDrawerTaskId(null)
    }
  }, [drawerTaskId, searchParams])

  useEffect(() => {
    const requestedAgentId = searchParams.get('agent')
    if (requestedAgentId && requestedAgentId !== agentSheetId) {
      setAgentSheetId(requestedAgentId)
      return
    }

    if (!requestedAgentId && agentSheetId !== null) {
      setAgentSheetId(null)
    }
  }, [agentSheetId, searchParams])

  const handleTabChange = (nextTab: Tab) => {
    setTab(nextTab)

    const nextParams = new URLSearchParams(searchParams)
    nextParams.set('tab', nextTab)
    setSearchParams(nextParams, { replace: true })
  }

  const openTaskDrawer = (taskId: string) => {
    setDrawerTaskId(taskId)
    const nextParams = new URLSearchParams(searchParams)
    nextParams.set('task', taskId)
    setSearchParams(nextParams, { replace: true })
  }

  const closeTaskDrawer = () => {
    setDrawerTaskId(null)
    const nextParams = new URLSearchParams(searchParams)
    nextParams.delete('task')
    setSearchParams(nextParams, { replace: true })
  }

  const openAgentSheet = (agentId: string) => {
    setAgentSheetId(agentId)
    const nextParams = new URLSearchParams(searchParams)
    nextParams.set('agent', agentId)
    setSearchParams(nextParams, { replace: true })
  }

  const closeAgentSheet = () => {
    setAgentSheetId(null)
    const nextParams = new URLSearchParams(searchParams)
    nextParams.delete('agent')
    setSearchParams(nextParams, { replace: true })
  }

  // ── Data queries ─────────────────────────────────────────────────────────────

  const { data: project, isLoading: loadingProject, isFetching: fetchingProject, error: errorProject, refetch: refetchProject } = useQuery({
    queryKey: ['projects', id],
    queryFn:  () => projectsApi.get(id!),
    enabled:  !!id,
  })

  const { data: workflow } = useQuery<Workflow | null>({
    queryKey: ['workflow', project?.workflow?.id],
    queryFn: () => workflowsApi.get(project!.workflow!.id),
    enabled: !!project?.workflow?.id,
  })
  const workflowActions = Array.isArray(workflow?.steps)
    ? Array.from(new Map(
        workflow.steps
          .flatMap((step) => step.actions.map(({ agentAction }) => [agentAction.key, { key: agentAction.key, label: agentAction.label }] as const)),
      ).values())
    : []

  const { data: tickets = [], isLoading: loadingTickets, isFetching: fetchingTickets, error: errorTickets } = useQuery({
    queryKey: ['tickets', id],
    queryFn:  () => ticketsApi.listByProject(id!),
    enabled:  !!id,
  })

  useProjectRealtime(id, drawerTaskId)

  // ── Mutations ─────────────────────────────────────────────────────────────────

  const invalidateTickets = () => qc.invalidateQueries({ queryKey: ['tickets', id] })

  const createRequestMutation = useMutation({
    mutationFn: (d: ProjectRequestPayload) => ticketsApi.createRequest(id!, d),
    onSuccess:  (result: ProjectRequestResult) => {
      invalidateTickets()
      setCreateOpen(false)
      setRequestDispatchError(result.dispatchError ?? null)
      toast.success(t('toast.request_submitted'), 'project-request')
    },
    onError: (err: unknown) => {
      const msg = (err as { message?: string })?.message
      setRequestDispatchError(msg ?? t('project.progress.error.request_creation_failed'))
    },
  })

  const createMutation = useMutation({
    mutationFn: (d: TicketTaskPayload & { ticketId?: string }) => {
      if (!d.ticketId) {
        throw new Error(t('project.detail.ticket_id_required'))
      }

      return ticketTasksApi.create(d.ticketId, {
        title: d.title,
        description: d.description,
        priority: d.priority,
        actionKey: d.actionKey,
        assignedAgentId: d.assignedAgentId,
        parentTaskId: d.parentTaskId,
      })
    },
    onSuccess:  () => { invalidateTickets(); setCreateOpen(false); toast.success(t('toast.ticket_created'), 'ticket-create') },
  })

  const transitionMutation = useMutation({
    mutationFn: ({ taskId }: { taskId: string }) => {
      setPendingTaskId(taskId)
      setTransitionError(null)
      return ticketsApi.advance(taskId)
    },
    onSuccess: () => { invalidateTickets(); setPendingTaskId(null) },
    onError: (err: unknown) => {
      setPendingTaskId(null)
      const msg = (err as { response?: { data?: { error?: string } } })?.response?.data?.error
      setTransitionError(msg ?? t('project.detail.transition_impossible'))
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (entity: Ticket | TicketTask) => (
      isTicket(entity) ? ticketsApi.delete(entity.id) : ticketTasksApi.delete(entity.id)
    ),
    onSuccess:  () => { invalidateTickets(); setDeleteTask(null); toast.success(t('toast.ticket_deleted'), 'ticket-delete') },
  })

  const projectNeedsTeamAssignment = project?.team === null
  const projectProgressBlockedReason = projectNeedsTeamAssignment
    ? t('project.progress.blocked_reason')
    : null

  const refreshAllData = () => {
    if( !id ) return
    qc.invalidateQueries({ queryKey: ['projects', id] })
    qc.invalidateQueries({ queryKey: ['tickets', id] })
    qc.invalidateQueries({ queryKey: ['workflow'] })
    qc.invalidateQueries({ queryKey: ['teams'] })
    qc.invalidateQueries({ queryKey: ['project-audit', id] })
    qc.invalidateQueries({ queryKey: ['project-tokens', id] })
  }

  // ── Derived data ───────────────────────────────────────────────────────────────

  if (loadingProject) return <PageSpinner />
  if (errorProject || !project) {
    return <ErrorMessage message={(errorProject as Error)?.message ?? t('project.detail.not_found')} onRetry={() => refetchProject()} />
  }

  const stories = tickets
  const taskMap = new Map<string, TicketTask>()
  const techTasks = tickets.flatMap((ticket) => {
    const items = ticket.tasks ?? []
    items.forEach((task) => taskMap.set(task.id, task))
    return items
  })
  const modules   = Array.isArray(project.modules) ? (project.modules as Module[]) : []

  // ── Render ─────────────────────────────────────────────────────────────────────

  return (
    <>
      <Link to="/projects" className="inline-flex items-center gap-1 text-sm mb-4" style={{ color: 'var(--muted)' }}>
        <ArrowLeft className="w-4 h-4" /> {t('project.detail.back_link')}
      </Link>

      <PageHeader
        title={project.name}
        description={project.description ?? undefined}
        onRefresh={refreshAllData}
        refreshTitle={t('common.action.refresh')}
        action={
          (tab === 'board' || tab === 'tasks') ? (
            <button
              className="btn-primary"
              disabled={tab === 'board' && projectNeedsTeamAssignment}
              title={tab === 'board' ? (projectProgressBlockedReason ?? undefined) : undefined}
              onClick={() => {
              if (tab === 'board' && projectNeedsTeamAssignment) return
              setCreateOpen(true)
              if (tab === 'board') setRequestDispatchError(null)
            }}>
              <Plus className="w-4 h-4" />
              {tab === 'board' ? t('project.detail.create_request') : t('project.detail.create_task')}
            </button>
          ) : undefined
        }
      />

      {/* Stats */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
        {[
          { label: t('project.detail.stats.stories_bugs'), value: stories.length },
          { label: t('project.detail.stats.tasks'), value: techTasks.length },
          { label: t('project.detail.stats.modules'), value: modules.length },
          { label: t('project.detail.stats.team'), value: project.team?.name ?? '—' },
        ].map(({ label, value }) => (
          <div key={label} className="card p-3 text-center">
            <p className="text-xl font-bold" style={{ color: 'var(--text)' }}>{value}</p>
            <p className="text-xs mt-0.5" style={{ color: 'var(--muted)' }}>{label}</p>
          </div>
        ))}
      </div>

      {projectNeedsTeamAssignment && (
        <div className="mb-5 rounded border px-4 py-3 text-sm" style={{ borderColor: 'rgba(245,158,11,0.35)', background: 'rgba(245,158,11,0.08)', color: '#92400e' }}>
          {t('project.progress.banner')}
        </div>
      )}

      {/* Tab bar */}
      <div className="flex gap-0 mb-5 border-b" style={{ borderColor: 'var(--border)' }}>
        {TABS.map(({ key, icon: Icon }) => (
          <button
            key={key}
            onClick={() => handleTabChange(key)}
            className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium transition-colors -mb-px border-b-2"
            style={{
              borderColor: tab === key ? 'var(--brand)' : 'transparent',
              color:       tab === key ? 'var(--brand)' : 'var(--muted)',
            }}
          >
            <Icon className="w-3.5 h-3.5" />
            {t(PROJECT_TAB_LABEL_KEYS[key])}
          </button>
        ))}
      </div>

      {/* ── Tabs ── */}
      {tab === 'general' && <ProjectGeneralTab project={project} projectId={id!} />}

      {tab === 'board' && (
        <ProjectBoardTab
          tickets={stories}
          loadingTickets={loadingTickets}
          errorTickets={errorTickets as Error | null}
          workflow={workflow}
          pendingTaskId={pendingTaskId}
          projectProgressBlockedReason={projectProgressBlockedReason}
          requestDispatchError={requestDispatchError}
          onClearRequestError={() => setRequestDispatchError(null)}
          transitionError={transitionError}
          onClearTransitionError={() => setTransitionError(null)}
          onTransition={(ticket) => transitionMutation.mutate({ taskId: ticket.id })}
          onDelete={setDeleteTask}
          onOpen={(ticket) => openTaskDrawer(ticket.id)}
        />
      )}

      {tab === 'tasks' && (
        <ProjectTasksTab
          techTasks={techTasks}
          taskMap={taskMap}
          tickets={tickets}
          loadingTickets={loadingTickets}
          onDelete={setDeleteTask}
          onOpen={(task) => openTaskDrawer(task.id)}
        />
      )}

      {tab === 'team' && (
        <ProjectTeamTab
          project={project}
          onOpenAgent={openAgentSheet}
          onGoToGeneral={() => handleTabChange('general')}
        />
      )}

      {tab === 'modules' && <ProjectModulesTab modules={modules} />}

      {tab === 'audit' && <ProjectAuditTab projectId={id!} />}

      {tab === 'tokens' && <ProjectTokensTab projectId={id!} />}

      {/* ── Modals ── */}
      <Modal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title={tab === 'board' ? t('project.detail.create_request') : t('project.detail.create_technical_task')}
      >
        {tab === 'board' ? (
          <RequestForm
            onSubmit={(d) => createRequestMutation.mutate(d)}
            loading={createRequestMutation.isPending}
            onCancel={() => setCreateOpen(false)}
          />
        ) : (
          <TaskForm
            initial={{ type: 'task' }}
            onSubmit={(d) => createMutation.mutate(d)}
            loading={createMutation.isPending}
            onCancel={() => setCreateOpen(false)}
            tickets={stories.map((story) => ({ id: story.id, title: story.title }))}
            actions={workflowActions}
          />
        )}
      </Modal>

      <ConfirmDialog
        open={!!deleteTask}
        onClose={() => setDeleteTask(null)}
        onConfirm={() => deleteTask && deleteMutation.mutate(deleteTask)}
        message={t('project.detail.delete_message', { title: deleteTask?.title ?? '' })}
        loading={deleteMutation.isPending}
      />

      <div className="relative">
        <ContentLoadingOverlay isLoading={(fetchingProject || fetchingTickets) && !loadingProject && !loadingTickets} label={t('project.item.loading')}       />

      </div>

      {/* ── Task drawer ── */}
      {drawerTaskId && (
        <TaskDrawer
          taskId={drawerTaskId}
          onClose={closeTaskDrawer}
          projectHasTeam={!projectNeedsTeamAssignment}
          isExpanded={isTaskDrawerExpanded}
          onExpandedChange={(expanded) => {
            const nextParams = new URLSearchParams(searchParams)
            if (expanded) {
              nextParams.set('drawer', 'expanded')
            } else {
              nextParams.delete('drawer')
            }
            setSearchParams(nextParams, { replace: true })
          }}
        />
      )}

      {id && (
        <AgentSheet
          projectId={id}
          agentId={agentSheetId}
          open={agentSheetId !== null}
          onClose={closeAgentSheet}
        />
      )}
    </>
  )
}
