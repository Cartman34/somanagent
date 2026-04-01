/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useEffect, useLayoutEffect, useRef, useState, useMemo } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertCircle,
  Bot,
  Clock3,
  Cpu,
  FileCode2,
  MessageSquare,
  RefreshCw,
  Reply,
  Send,
  Sparkles,
  Wrench,
} from 'lucide-react'
import Modal from '@/components/ui/Modal'
import { PageSpinner } from '@/components/ui/Spinner'
import EntityId from '@/components/ui/EntityId'
import ErrorMessage from '@/components/ui/ErrorMessage'
import Markdown from '@/components/ui/Markdown'
import { agentsApi } from '@/api/agents'
import { chatApi } from '@/api/chat'
import { rolesApi } from '@/api/roles'
import { translationsApi } from '@/api/translations'
import type { ChatMessage, SkillSummary } from '@/types'

interface AgentSheetProps {
  projectId: string
  agentId: string | null
  open: boolean
  onClose: () => void
}

type TabKey = 'details' | 'chat'

interface MessageRowLabels {
  authorYou: string
  authorError: string
  errorNoDetail: string
  noOutput: string
}

function MessageRow({ message, labels }: { message: ChatMessage; labels: MessageRowLabels }) {
  const isHuman = message.author === 'human'
  const metadata = message.metadata ?? {}
  const hasContent = message.content.trim().length > 0
  const tone = isHuman
    ? {
        background: 'var(--surface)',
        badgeBackground: 'var(--surface2)',
        badgeColor: 'var(--text)',
      }
    : message.isError
      ? {
          background: 'rgba(220, 38, 38, 0.12)',
          badgeBackground: 'rgba(220, 38, 38, 0.18)',
          badgeColor: '#f87171',
        }
      : {
          background: 'var(--brand-dim)',
          badgeBackground: 'rgba(255,255,255,0.12)',
          badgeColor: 'var(--text)',
        }

  return (
    <div
      className="item-chat-message rounded-xl border p-3"
      style={{ borderColor: 'var(--border)', background: tone.background }}
    >
      <div className="flex items-center gap-2 text-xs mb-2">
        <span
          className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full"
          style={{ background: tone.badgeBackground, color: tone.badgeColor }}
        >
          {isHuman ? <MessageSquare className="w-3 h-3" /> : <Bot className="w-3 h-3" />}
          {isHuman ? labels.authorYou : (message.isError ? labels.authorError : 'Agent')}
        </span>
        <span style={{ color: 'var(--muted)' }}>
          {new Date(message.createdAt).toLocaleString('fr-FR')}
        </span>
        <span className="ml-auto font-mono" style={{ color: 'var(--muted)' }}>
          {message.exchangeId.slice(0, 8)}
        </span>
      </div>

      {hasContent ? (
        message.isError ? (
          <p className="text-sm whitespace-pre-wrap break-words" style={{ color: 'var(--text)' }}>
            {message.content}
          </p>
        ) : (
          <Markdown content={message.content} className="text-sm" />
        )
      ) : (
        <div className="text-sm rounded-lg px-3 py-2 border border-dashed" style={{ color: 'var(--muted)', borderColor: 'var(--border)' }}>
          {message.isError ? labels.errorNoDetail : labels.noOutput}
        </div>
      )}

      {Object.keys(metadata).length > 0 && (
        <div className="mt-3 flex flex-wrap gap-2 text-xs">
          {(typeof metadata.connector === 'string' || typeof metadata.connector === 'number') && (
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full" style={{ background: 'var(--surface)', color: 'var(--muted)', border: '1px solid var(--border)' }}>
              <Wrench className="w-3 h-3" /> {String(metadata.connector)}
            </span>
          )}
          {(typeof metadata.model === 'string' || typeof metadata.model === 'number') && (
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full" style={{ background: 'var(--surface)', color: 'var(--muted)', border: '1px solid var(--border)' }}>
              <Cpu className="w-3 h-3" /> {String(metadata.model)}
            </span>
          )}
          {typeof metadata.duration_ms === 'number' && (
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full" style={{ background: 'var(--surface)', color: 'var(--muted)', border: '1px solid var(--border)' }}>
              <Clock3 className="w-3 h-3" /> {(Number(metadata.duration_ms) / 1000).toFixed(1)}s
            </span>
          )}
          {typeof metadata.input_tokens === 'number' && typeof metadata.output_tokens === 'number' && (
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full" style={{ background: 'var(--surface)', color: 'var(--muted)', border: '1px solid var(--border)' }}>
              <Cpu className="w-3 h-3" /> {Number(metadata.input_tokens) + Number(metadata.output_tokens)} tok
            </span>
          )}
          {(typeof metadata.exception === 'string' || typeof metadata.exception === 'number') && (
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full" style={{ background: 'rgba(220, 38, 38, 0.12)', color: '#f87171', border: '1px solid rgba(220, 38, 38, 0.25)' }}>
              <AlertCircle className="w-3 h-3" /> {String(metadata.exception)}
            </span>
          )}
        </div>
      )}
    </div>
  )
}

function SkillCard({
  skill,
  selected,
  onSelect,
}: {
  skill: SkillSummary
  selected: boolean
  onSelect: () => void
}) {
  return (
    <button
      type="button"
      onClick={onSelect}
      className="item-skill w-full text-left rounded-xl border p-3 transition-colors"
      style={{
        borderColor: selected ? 'var(--brand)' : 'var(--border)',
        background: selected ? 'var(--brand-dim)' : 'var(--surface)',
      }}
    >
      <div className="flex items-center gap-2">
        <Sparkles className="w-4 h-4 flex-shrink-0" style={{ color: 'var(--brand)' }} />
        <span className="text-sm font-medium" style={{ color: 'var(--text)' }}>{skill.name}</span>
      </div>
      {skill.slug && (
        <p className="text-xs mt-1 font-mono" style={{ color: 'var(--muted)' }}>{skill.slug}</p>
      )}
      {skill.description && (
        <p className="text-xs mt-2" style={{ color: 'var(--muted)' }}>{skill.description}</p>
      )}
    </button>
  )
}

function TabButton({
  active,
  children,
  onClick,
}: {
  active: boolean
  children: React.ReactNode
  onClick: () => void
}) {
  return (
    <button
      type="button"
      className="btn-secondary"
      onClick={onClick}
      style={{
        background: active ? 'var(--brand-dim)' : undefined,
        color: active ? 'var(--text)' : undefined,
        borderColor: active ? 'var(--brand)' : undefined,
      }}
    >
      {children}
    </button>
  )
}

export default function AgentSheet({ projectId, agentId, open, onClose }: AgentSheetProps) {
  const qc = useQueryClient()
  const historyContainerRef = useRef<HTMLDivElement | null>(null)
  const historyBottomRef = useRef<HTMLDivElement | null>(null)
  const skillContentRef = useRef<HTMLDivElement | null>(null)
  const shouldStickToBottomRef = useRef(true)
  const previousLastMessageIdRef = useRef<string | null>(null)
  const [draft, setDraft] = useState('')
  const [replyingTo, setReplyingTo] = useState<string | null>(null)
  const [replyInput, setReplyInput] = useState('')
  const [selectedSkillId, setSelectedSkillId] = useState<string | null>(null)
  const [activeTab, setActiveTab] = useState<TabKey>('chat')
  const [desktopPanel, setDesktopPanel] = useState<'chat' | 'skill'>('chat')
  const [isDesktop, setIsDesktop] = useState(false)

  const { data: agentSheetI18n } = useQuery({
    queryKey: ['ui-translations', 'agent-sheet'],
    queryFn: () => translationsApi.list([
      'chat.reply', 'chat.reply_placeholder',
      'common.action.cancel', 'common.action.refresh',
      'agents.sheet.modal_title', 'agents.sheet.load_error',
      'agents.sheet.message.author_you', 'agents.sheet.message.author_error',
      'agents.sheet.message.error_no_detail', 'agents.sheet.message.no_output',
      'agents.sheet.status.error', 'agents.sheet.status.working', 'agents.sheet.status.available',
      'agents.sheet.summary.no_role', 'agents.sheet.summary.connector', 'agents.sheet.summary.model',
      'agents.sheet.summary.timeout', 'agents.sheet.summary.active_status',
      'agents.sheet.summary.yes', 'agents.sheet.summary.no', 'agents.sheet.summary.active_tasks',
      'agents.sheet.knowledge.title', 'agents.sheet.knowledge.hint',
      'agents.sheet.knowledge.no_role', 'agents.sheet.knowledge.no_skills',
      'agents.sheet.skill.none_selected', 'agents.sheet.skill.no_content', 'agents.sheet.skill.content_tab',
      'agents.sheet.chat.title', 'agents.sheet.chat.description', 'agents.sheet.chat.empty',
      'agents.sheet.chat.hello', 'agents.sheet.chat.message_placeholder',
      'agents.sheet.chat.sending', 'agents.sheet.chat.send',
    ]),
    staleTime: Infinity,
  })
  const tt = (key: string) => agentSheetI18n?.translations[key] ?? key

  const agentQuery = useQuery({
    queryKey: ['agent-detail', agentId],
    queryFn: () => agentsApi.get(agentId!),
    enabled: open && !!agentId,
  })

  const roleQuery = useQuery({
    queryKey: ['role-detail', agentQuery.data?.role?.id],
    queryFn: () => rolesApi.get(agentQuery.data!.role!.id),
    enabled: open && !!agentQuery.data?.role?.id,
  })

  const statusQuery = useQuery({
    queryKey: ['agent-status', agentId],
    queryFn: () => agentsApi.getStatus(agentId!),
    enabled: open && !!agentId,
    refetchInterval: 30_000,
  })

  const historyQuery = useQuery({
    queryKey: ['project-agent-chat', projectId, agentId],
    queryFn: () => chatApi.history(projectId, agentId!),
    enabled: open && !!agentId,
  })

  const sendMutation = useMutation({
    mutationFn: (content: string) => chatApi.send(projectId, agentId!, content),
    onSuccess: async () => {
      setDraft('')
      shouldStickToBottomRef.current = true
      setActiveTab('chat')
      setDesktopPanel('chat')
      await qc.invalidateQueries({ queryKey: ['project-agent-chat', projectId, agentId] })
    },
  })

  const replyMutation = useMutation({
    mutationFn: ({ content, replyToMessageId }: { content: string; replyToMessageId: string }) =>
      chatApi.reply(projectId, agentId!, content, replyToMessageId),
    onSuccess: async () => {
      setReplyInput('')
      setReplyingTo(null)
      await qc.invalidateQueries({ queryKey: ['project-agent-chat', projectId, agentId] })
    },
  })

  const isLoading = agentQuery.isLoading || statusQuery.isLoading || historyQuery.isLoading
  const error = (agentQuery.error as Error | null)
    ?? (roleQuery.error as Error | null)
    ?? (statusQuery.error as Error | null)
    ?? (historyQuery.error as Error | null)
  const agent = agentQuery.data
  const role = roleQuery.data
  const history = historyQuery.data ?? []
  const skills = role?.skills ?? []
  const selectedSkill = skills.find((skill) => skill.id === selectedSkillId) ?? skills[0] ?? null
  const runtimeStatus = statusQuery.data?.status ?? 'idle'
  const runtimeBadgeClass = runtimeStatus === 'error'
    ? 'badge-red'
    : runtimeStatus === 'working'
      ? 'badge-orange'
      : 'badge-green'
  const runtimeLabel = runtimeStatus === 'error'
    ? tt('agents.sheet.status.error')
    : runtimeStatus === 'working'
      ? tt('agents.sheet.status.working')
      : tt('agents.sheet.status.available')

  /**
   * Represents a group of messages sharing the same exchange ID.
   */
  interface MessageGroup {
    /** Exchange identifier. */
    exchangeId: string
    /** Messages belonging to this exchange. */
    messages: ChatMessage[]
  }

  /**
   * Messages grouped by exchangeId for threaded display.
   */
  const groupedMessages = useMemo((): MessageGroup[] => {
    const groups: Map<string, MessageGroup> = new Map()

    for (const msg of history) {
      const exchangeId = msg.exchangeId

      if (!groups.has(exchangeId)) {
        groups.set(exchangeId, {
          exchangeId,
          messages: [],
        })
      }

      groups.get(exchangeId)!.messages.push(msg)
    }

    return Array.from(groups.values()).sort((a, b) =>
      new Date(a.messages[0].createdAt).getTime() - new Date(b.messages[0].createdAt).getTime()
    )
  }, [history])

  useEffect(() => {
    const media = window.matchMedia('(min-width: 1024px)')
    const syncDesktop = () => setIsDesktop(media.matches)

    syncDesktop()
    media.addEventListener('change', syncDesktop)

    return () => media.removeEventListener('change', syncDesktop)
  }, [])

  useEffect(() => {
    if (skills.length === 0) {
      setSelectedSkillId(null)
      return
    }

    if (!selectedSkillId || !skills.some((skill) => skill.id === selectedSkillId)) {
      setSelectedSkillId(skills[0].id)
    }
  }, [selectedSkillId, skills])

  useEffect(() => {
    if (!open) {
      previousLastMessageIdRef.current = null
      shouldStickToBottomRef.current = true
      setDraft('')
      setActiveTab('chat')
      setDesktopPanel('chat')
      return
    }

    const container = historyContainerRef.current
    if (!container) return

    const lastMessageId = history[history.length - 1]?.id ?? null
    const hasNewLastMessage = lastMessageId !== previousLastMessageIdRef.current

    if (hasNewLastMessage && shouldStickToBottomRef.current) {
      container.scrollTop = container.scrollHeight
    }

    previousLastMessageIdRef.current = lastMessageId
  }, [history, open])

  useEffect(() => {
    const isChatVisible = activeTab === 'chat' || desktopPanel === 'chat'
    if (!open || !isChatVisible || !shouldStickToBottomRef.current) return

    requestAnimationFrame(() => {
      scrollHistoryToBottom()
    })
  }, [activeTab, desktopPanel, open, history.length])

  useLayoutEffect(() => {
    if (!open || isLoading) return

    requestAnimationFrame(() => {
      scrollHistoryToBottom()
      shouldStickToBottomRef.current = true
    })
  }, [open, isLoading])

  useLayoutEffect(() => {
    if (!open || isLoading) return

    const isChatVisible = activeTab === 'chat' || desktopPanel === 'chat'
    if (!isChatVisible) return

    requestAnimationFrame(() => {
      scrollHistoryToBottom()
      shouldStickToBottomRef.current = true
    })
  }, [activeTab, desktopPanel, open, isLoading])

  useLayoutEffect(() => {
    if (!open || isLoading) return

    const isSkillVisible = isDesktop ? desktopPanel === 'skill' : activeTab === 'details'
    if (!isSkillVisible) return

    skillContentRef.current?.scrollTo({ top: 0, behavior: 'auto' })
  }, [selectedSkill?.id, desktopPanel, activeTab, open, isLoading, isDesktop])

  const handleHistoryScroll = () => {
    const container = historyContainerRef.current
    if (!container) return

    const distanceFromBottom = container.scrollHeight - container.scrollTop - container.clientHeight
    shouldStickToBottomRef.current = distanceFromBottom < 48
  }

  const handleSend = () => {
    const trimmed = draft.trim()
    if (trimmed === '') return
    sendMutation.mutate(trimmed)
  }

  const handleQuickSend = (content: string) => {
    if (sendMutation.isPending) return

    setDraft('')
    shouldStickToBottomRef.current = true
    sendMutation.mutate(content)
  }

  const scrollHistoryToBottom = () => {
    const container = historyContainerRef.current
    if (container) {
      container.scrollTo({ top: container.scrollHeight, behavior: 'auto' })
    }

    historyBottomRef.current?.scrollIntoView({ block: 'end' })
  }

  const renderAgentSummary = () => (
    <div className="item-agent rounded-2xl p-4 border" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
      {(() => {
        const currentAgent = agent!

        return (
          <>
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-lg font-semibold" style={{ color: 'var(--text)' }}>{currentAgent.name}</p>
          <p className="text-sm mt-1" style={{ color: 'var(--muted)' }}>
            {currentAgent.role?.name ?? tt('agents.sheet.summary.no_role')}
          </p>
        </div>
        <span className={`${runtimeBadgeClass} text-xs`}>{runtimeLabel}</span>
      </div>

      <div className="mt-4 space-y-2 text-sm">
        <div className="flex items-center justify-between gap-4">
          <span style={{ color: 'var(--muted)' }}>{tt('agents.sheet.summary.connector')}</span>
          <span style={{ color: 'var(--text)' }}>{currentAgent.connectorLabel}</span>
        </div>
        <div className="flex items-center justify-between gap-4">
          <span style={{ color: 'var(--muted)' }}>{tt('agents.sheet.summary.model')}</span>
          <code style={{ color: 'var(--text)' }}>{currentAgent.config.model}</code>
        </div>
        <div className="flex items-center justify-between gap-4">
          <span style={{ color: 'var(--muted)' }}>{tt('agents.sheet.summary.timeout')}</span>
          <span style={{ color: 'var(--text)' }}>{currentAgent.config.timeout}s</span>
        </div>
        <div className="flex items-center justify-between gap-4">
          <span style={{ color: 'var(--muted)' }}>{tt('agents.sheet.summary.active_status')}</span>
          <span style={{ color: 'var(--text)' }}>{currentAgent.isActive ? tt('agents.sheet.summary.yes') : tt('agents.sheet.summary.no')}</span>
        </div>
        <div className="flex items-center justify-between gap-4">
          <span style={{ color: 'var(--muted)' }}>{tt('agents.sheet.summary.active_tasks')}</span>
          <span style={{ color: 'var(--text)' }}>{statusQuery.data?.activeTaskCount ?? 0}</span>
        </div>
      </div>

      <div className="mt-4">
        <EntityId id={currentAgent.id} />
      </div>

      {currentAgent.description && (
        <p className="mt-4 text-sm whitespace-pre-wrap" style={{ color: 'var(--text)' }}>
          {currentAgent.description}
        </p>
      )}
          </>
        )
      })()}
    </div>
  )

  const renderKnowledgePanel = () => (
    <div className="rounded-2xl border min-h-0 h-full overflow-hidden flex flex-col" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
      <div className="shrink-0 px-4 py-4 border-b" style={{ borderColor: 'var(--border)' }}>
        <p className="text-sm font-semibold" style={{ color: 'var(--text)' }}>{tt('agents.sheet.knowledge.title')}</p>
        <p className="text-xs mt-1" style={{ color: 'var(--muted)' }}>
          {tt('agents.sheet.knowledge.hint')}
        </p>
      </div>

      <div className="basis-0 flex-1 min-h-0 overflow-y-auto p-4 space-y-4">
        {!role ? (
          <div className="text-sm" style={{ color: 'var(--muted)' }}>
            {tt('agents.sheet.knowledge.no_role')}
          </div>
        ) : (
          <>
            <div className="rounded-xl border p-3" style={{ borderColor: 'var(--border)', background: 'var(--surface)' }}>
              <p className="text-sm font-semibold" style={{ color: 'var(--text)' }}>{role.name}</p>
              <p className="text-xs mt-1 font-mono" style={{ color: 'var(--muted)' }}>{role.slug}</p>
              {role.description && (
                <p className="text-sm mt-3 whitespace-pre-wrap" style={{ color: 'var(--text)' }}>{role.description}</p>
              )}
            </div>

            {skills.length === 0 ? (
              <div className="text-sm" style={{ color: 'var(--muted)' }}>
                {tt('agents.sheet.knowledge.no_skills')}
              </div>
            ) : (
              <div className="list-skill space-y-2">
                {skills.map((skill) => (
                  <SkillCard
                    key={skill.id}
                    skill={skill}
                    selected={selectedSkill?.id === skill.id}
                    onSelect={() => {
                      setSelectedSkillId(skill.id)
                      setDesktopPanel('skill')
                    }}
                  />
                ))}
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )

  const renderSkillContent = () => (
    <div className="rounded-2xl border min-h-0 h-full overflow-hidden flex flex-col" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
      <div className="shrink-0 flex items-center gap-2 px-4 py-4 border-b" style={{ borderColor: 'var(--border)' }}>
        <FileCode2 className="w-4 h-4" style={{ color: 'var(--brand)' }} />
        <div className="min-w-0">
          <p className="text-sm font-semibold truncate" style={{ color: 'var(--text)' }}>
            {selectedSkill?.name ?? tt('agents.sheet.skill.none_selected')}
          </p>
          {selectedSkill?.filePath && (
            <p className="text-xs font-mono truncate" style={{ color: 'var(--muted)' }}>
              {selectedSkill.filePath}
            </p>
          )}
        </div>
      </div>

      <div ref={skillContentRef} className="basis-0 flex-1 min-h-0 overflow-y-auto p-4">
        <pre
          className="min-h-full text-xs whitespace-pre-wrap break-words"
          style={{ color: 'var(--text)' }}
        >
          {selectedSkill?.content?.trim() || tt('agents.sheet.skill.no_content')}
        </pre>
      </div>
    </div>
  )

  const renderChatPanel = () => (
    <div className="rounded-2xl border min-h-0 h-full overflow-hidden flex flex-col" style={{ borderColor: 'var(--border)', background: 'var(--surface2)' }}>
      <div className="shrink-0 flex items-center justify-between gap-3 px-4 py-4 border-b" style={{ borderColor: 'var(--border)' }}>
        <div>
          <p className="text-sm font-semibold" style={{ color: 'var(--text)' }}>{tt('agents.sheet.chat.title')}</p>
          <p className="text-xs mt-1" style={{ color: 'var(--muted)' }}>
            {tt('agents.sheet.chat.description')}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <span className="badge-blue text-xs">{history.length} message{history.length > 1 ? 's' : ''}</span>
          <button className="btn-secondary" onClick={() => historyQuery.refetch()} disabled={historyQuery.isFetching}>
            <RefreshCw className={`w-4 h-4 ${historyQuery.isFetching ? 'animate-spin' : ''}`} />
            {tt('common.action.refresh')}
          </button>
        </div>
      </div>

      <div
        ref={historyContainerRef}
        onScroll={handleHistoryScroll}
        className="list-chat-message basis-0 flex-1 min-h-0 overflow-y-auto p-4 space-y-4"
      >
        {history.length === 0 ? (
          <div className="h-full min-h-[18rem] flex items-center justify-center text-sm" style={{ color: 'var(--muted)' }}>
            {tt('agents.sheet.chat.empty')}
          </div>
        ) : (
          groupedMessages.map((group) => (
            <div key={group.exchangeId} className="space-y-2">
              {group.messages.map((msg, idx) => {
                const isReply = msg.replyToMessageId !== null
                const canReply = idx === 0 && !isReply

                return (
                  <div>
                    <MessageRow message={msg} labels={{
                      authorYou: tt('agents.sheet.message.author_you'),
                      authorError: tt('agents.sheet.message.author_error'),
                      errorNoDetail: tt('agents.sheet.message.error_no_detail'),
                      noOutput: tt('agents.sheet.message.no_output'),
                    }} />
                    {canReply && (
                      <button
                        type="button"
                        onClick={() => { setReplyingTo(msg.id); setReplyInput('') }}
                        className="flex items-center gap-1 mt-1 text-xs transition-colors hover:opacity-70"
                        style={{ color: 'var(--muted)' }}
                      >
                        <Reply className="w-3 h-3" />
                        {tt('chat.reply')}
                      </button>
                    )}
                    {replyingTo === msg.id && (
                      <form
                        onSubmit={(e) => { e.preventDefault(); replyMutation.mutate({ content: replyInput.trim(), replyToMessageId: replyingTo }) }}
                        className="mt-2 flex gap-2"
                      >
                        <textarea
                          className="input flex-1 text-sm resize-none"
                          rows={2}
                          value={replyInput}
                          onChange={(e) => setReplyInput(e.target.value)}
                          placeholder={tt('chat.reply_placeholder')}
                          disabled={replyMutation.isPending}
                        />
                        <div className="flex flex-col gap-1">
                          <button
                            type="submit"
                            className="btn-primary px-3 py-1"
                            disabled={!replyInput.trim() || replyMutation.isPending}
                          >
                            <Send className="w-3 h-3" />
                          </button>
                          <button
                            type="button"
                            onClick={() => { setReplyingTo(null); setReplyInput('') }}
                            className="text-xs"
                            style={{ color: 'var(--muted)' }}
                          >
                            {tt('common.action.cancel')}
                          </button>
                        </div>
                      </form>
                    )}
                  </div>
                )
              })}
            </div>
          ))
        )}
        <div ref={historyBottomRef} aria-hidden="true" className="h-px w-full" />
      </div>

      <div className="shrink-0 border-t p-4 space-y-3" style={{ borderColor: 'var(--border)' }}>
        {sendMutation.isError && (
          <p className="text-sm" style={{ color: '#f87171' }}>
            {(sendMutation.error as Error).message}
          </p>
        )}

        <div className="flex gap-2 flex-wrap">
          <button
            type="button"
            className="btn-secondary"
            onClick={() => handleQuickSend('Salut')}
            disabled={sendMutation.isPending}
          >
            <Send className="w-4 h-4" />
            {tt('agents.sheet.chat.hello')}
          </button>
        </div>

        <div className="flex flex-col sm:flex-row gap-3 items-stretch sm:items-end">
          <textarea
            className="input min-h-[6rem]"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault()
                handleSend()
              }
            }}
            placeholder={tt('agents.sheet.chat.message_placeholder')}
            disabled={sendMutation.isPending}
          />
          <button
            className="btn-primary sm:self-stretch"
            onClick={handleSend}
            disabled={sendMutation.isPending || draft.trim() === ''}
          >
            <Send className="w-4 h-4" />
            {sendMutation.isPending ? tt('agents.sheet.chat.sending') : tt('agents.sheet.chat.send')}
          </button>
        </div>
      </div>
    </div>
  )

  return (
    <Modal open={open} onClose={onClose} title={agent ? `Agent ${agent.name}` : tt('agents.sheet.modal_title')} size="2xl">
      {!agentId ? null : isLoading ? (
        <PageSpinner />
      ) : error || !agent ? (
        <ErrorMessage message={error?.message ?? tt('agents.sheet.load_error')} />
      ) : (
        <div className="flex min-h-0 h-[min(82vh,860px)] max-h-full flex-col gap-4">
          <div className="flex items-center justify-between gap-3 border-b pb-3" style={{ borderColor: 'var(--border)' }}>
            <div className="min-w-0">
              <p className="text-sm font-semibold truncate" style={{ color: 'var(--text)' }}>{agent.name}</p>
              <p className="text-xs mt-1 truncate" style={{ color: 'var(--muted)' }}>
                {agent.role?.name ?? tt('agents.sheet.summary.no_role')} • {agent.connectorLabel}
              </p>
            </div>
            <div className="hidden lg:flex items-center gap-2">
              <button
                type="button"
                className="btn-secondary"
                onClick={() => {
                  setDesktopPanel('chat')
                  handleQuickSend('Salut')
                }}
                disabled={sendMutation.isPending}
              >
                <Send className="w-4 h-4" />
                {tt('agents.sheet.chat.hello')}
              </button>
            </div>
          </div>

          <div className="lg:hidden flex gap-2 overflow-x-auto" style={{ scrollbarWidth: 'thin' }}>
            <TabButton active={activeTab === 'chat'} onClick={() => setActiveTab('chat')}>Chat</TabButton>
            <TabButton active={activeTab === 'details'} onClick={() => setActiveTab('details')}>{tt('agents.sheet.knowledge.title')}</TabButton>
          </div>

          {isDesktop ? (
            <div className="min-h-0 flex-1 grid gap-4 lg:grid-cols-[340px,minmax(0,1fr)]">
              <div className="min-h-0 flex flex-col gap-4">
                {renderAgentSummary()}
                <div className="min-h-0 flex-1">
                  {renderKnowledgePanel()}
                </div>
              </div>

              <div className="min-h-0 flex flex-col gap-4">
                <div className="flex gap-2">
                  <TabButton active={desktopPanel === 'chat'} onClick={() => setDesktopPanel('chat')}>Chat</TabButton>
                  <TabButton active={desktopPanel === 'skill'} onClick={() => setDesktopPanel('skill')}>{tt('agents.sheet.skill.content_tab')}</TabButton>
                </div>
                <div className="min-h-0 flex-1">
                  {desktopPanel === 'chat' ? renderChatPanel() : renderSkillContent()}
                </div>
              </div>
            </div>
          ) : (
            <div className="min-h-0 flex-1">
              {activeTab === 'chat' ? (
                renderChatPanel()
              ) : (
                <div className="min-h-0 h-full flex flex-col gap-4 overflow-y-auto pr-1">
                  {renderAgentSummary()}
                  <div className="min-h-0">
                    {renderKnowledgePanel()}
                  </div>
                  {selectedSkill && renderSkillContent()}
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </Modal>
  )
}
