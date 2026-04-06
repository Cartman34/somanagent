/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState, useRef, useEffect, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Send, Bot, User, Reply, Pencil } from 'lucide-react'
import { chatApi } from '@/api/chat'
import { projectsApi } from '@/api/projects'
import { agentsApi } from '@/api/agents'
import { useTranslation } from '@/hooks/useTranslation'
import { PageSpinner } from '@/components/ui/Spinner'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'
import type { ChatMessage } from '@/types'

const CHAT_PAGE_TRANSLATION_KEYS = [
  'chat.page.title',
  'chat.page.description',
  'chat.item.loading',
  'common.action.refresh',
  'chat.reply',
  'chat.reply_label',
  'chat.reply_placeholder',
  'chat.edited_label',
  'chat.edit_placeholder',
  'chat.send_reply',
  'chat.select_project',
  'chat.select_agent',
  'chat.select_prompt',
  'chat.no_messages',
  'chat.input_placeholder',
  'common.action.edit',
  'common.action.save',
  'common.action.cancel',
] as const

interface MessageGroup {
  exchangeId: string
  messages: ChatMessage[]
  parentId: string | null
}

/**
 * Chat page — interact with agents through a conversational interface.
 */
export default function ChatPage() {
  const qc = useQueryClient()
  const [projectId, setProjectId] = useState('')
  const [agentId, setAgentId]     = useState('')
  const [input, setInput]         = useState('')
  const [replyingTo, setReplyingTo] = useState<string | null>(null)
  const [replyInput, setReplyInput] = useState('')
  const [editingMessageId, setEditingMessageId] = useState<string | null>(null)
  const [editInput, setEditInput] = useState('')
  const bottomRef = useRef<HTMLDivElement>(null)
  const replyInputRef = useRef<HTMLTextAreaElement>(null)
  const editInputRef = useRef<HTMLTextAreaElement>(null)

  const { data: projects } = useQuery({ queryKey: ['projects'], queryFn: projectsApi.list })
  const { data: agents }   = useQuery({ queryKey: ['agents'], queryFn: agentsApi.list })

  const { data: messages, isLoading, isFetching } = useQuery({
    queryKey: ['chat', projectId, agentId],
    queryFn: () => chatApi.history(projectId, agentId),
    enabled: !!(projectId && agentId),
    refetchInterval: 5000,
  })

  const { t, formatTime } = useTranslation(CHAT_PAGE_TRANSLATION_KEYS)

  const sendMutation = useMutation({
    mutationFn: (content: string) => chatApi.send(projectId, agentId, content),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['chat', projectId, agentId] })
      setInput('')
    },
  })

  const replyMutation = useMutation({
    mutationFn: ({ content, replyToMessageId }: { content: string; replyToMessageId: string }) =>
      chatApi.reply(projectId, agentId, content, replyToMessageId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['chat', projectId, agentId] })
      setReplyInput('')
      setReplyingTo(null)
    },
  })

  const editMutation = useMutation({
    mutationFn: ({ messageId, content }: { messageId: string; content: string }) =>
      chatApi.updateMessage(projectId, agentId, messageId, content),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['chat', projectId, agentId] })
      setEditInput('')
      setEditingMessageId(null)
    },
  })

  const groupedMessages = useMemo(() => {
    if (!messages) return []

    const groups: Map<string, MessageGroup> = new Map()

    for (const msg of messages) {
      const exchangeId = msg.exchangeId

      if (!groups.has(exchangeId)) {
        groups.set(exchangeId, { exchangeId, messages: [], parentId: msg.replyToMessageId ?? null })
      }

      groups.get(exchangeId)!.messages.push(msg)
    }

    return Array.from(groups.values()).sort((a, b) =>
      new Date(a.messages[0].createdAt).getTime() - new Date(b.messages[0].createdAt).getTime()
    )
  }, [messages])

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [messages])

  useEffect(() => {
    if (replyingTo && replyInputRef.current) {
      replyInputRef.current.focus()
    }
  }, [replyingTo])

  useEffect(() => {
    if (editingMessageId && editInputRef.current) {
      editInputRef.current.focus()
    }
  }, [editingMessageId])

  const handleSend = (e: React.FormEvent) => {
    e.preventDefault()
    if (input.trim()) sendMutation.mutate(input.trim())
  }

  const handleReply = (e: React.FormEvent) => {
    e.preventDefault()
    if (replyInput.trim() && replyingTo) {
      replyMutation.mutate({ content: replyInput.trim(), replyToMessageId: replyingTo })
    }
  }

  const startReply = (messageId: string) => {
    setEditingMessageId(null)
    setEditInput('')
    setReplyingTo(messageId)
    setReplyInput('')
  }

  const cancelReply = () => {
    setReplyingTo(null)
    setReplyInput('')
  }

  const startEdit = (message: ChatMessage) => {
    setReplyingTo(null)
    setReplyInput('')
    setEditingMessageId(message.id)
    setEditInput(message.content)
  }

  const cancelEdit = () => {
    setEditingMessageId(null)
    setEditInput('')
  }

  const selectedAgent = agents?.find((a) => a.id === agentId)

  return (
    <>
      <PageHeader title={t('chat.page.title')} description={t('chat.page.description')}
        onRefresh={() => { qc.invalidateQueries({ queryKey: ['projects'] }); qc.invalidateQueries({ queryKey: ['agents'] }); qc.invalidateQueries({ queryKey: ['chat'] }) }}
        refreshTitle={t('common.action.refresh')} />

      <div className="grid grid-cols-2 gap-4 mb-6">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">{t('chat.select_project')}</label>
          <select className="input" value={projectId} onChange={(e) => { setProjectId(e.target.value); setAgentId('') }}>
            <option value="">— {t('chat.select_prompt')} —</option>
            {projects?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">{t('chat.select_agent')}</label>
          <select className="input" value={agentId} onChange={(e) => setAgentId(e.target.value)} disabled={!projectId}>
            <option value="">— {t('chat.select_prompt')} —</option>
            {agents?.map((a) => <option key={a.id} value={a.id}>{a.name}{a.role ? ` — ${a.role.name}` : ''}</option>)}
          </select>
        </div>
      </div>

      {!(projectId && agentId) ? (
        <div className="card p-8 text-center text-gray-400">
          <Bot className="w-12 h-12 mx-auto mb-3 opacity-30" />
          <p className="text-sm">{t('chat.select_prompt')}</p>
        </div>
      ) : (
        <div className="relative card flex flex-col" style={{ height: '60vh' }}>
          <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={t('chat.item.loading')} />
          <div className="px-4 py-3 border-b border-gray-100 flex items-center gap-2">
            <Bot className="w-4 h-4 text-brand-600" />
            <span className="text-sm font-medium text-gray-900">{selectedAgent?.name}</span>
            {selectedAgent?.role && <span className="badge-blue text-xs">{selectedAgent.role.name}</span>}
          </div>

          <div className="list-chat-message flex-1 overflow-y-auto px-4 py-4 space-y-4">
            {isLoading ? <PageSpinner /> : groupedMessages.length === 0 ? (
              <p className="text-center text-sm text-gray-400 mt-8">{t('chat.no_messages')}</p>
            ) : (
              groupedMessages.map((group) => (
                <div key={group.exchangeId} className="space-y-2">
                  {group.messages.map((msg, idx) => {
                    const isReply = msg.replyToMessageId !== null
                    const canReply = idx === 0 && !isReply
                    const isEditable = msg.author === 'human'
                    const isEdited = typeof msg.metadata?.editedAt === 'string'

                    return (
                      <div key={msg.id} className={`item-chat-message flex gap-2 ${msg.author === 'human' ? 'flex-row-reverse' : ''}`}>
                        <div className={`flex flex-col items-center`}>
                          <div className={`w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 ${msg.author === 'human' ? 'bg-brand-600' : 'bg-gray-200'}`}>
                            {msg.author === 'human' ? <User className="w-3.5 h-3.5 text-white" /> : <Bot className="w-3.5 h-3.5 text-gray-600" />}
                          </div>
                        </div>
                        <div className={`flex-1 max-w-[75%] ${isReply ? 'ml-8' : ''}`}>
                          <div className={`rounded-xl px-3 py-2 text-sm ${isReply ? 'bg-gray-50 border border-gray-100' : ''} ${msg.author === 'human' ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-800'}`}>
                            {editingMessageId === msg.id ? (
                              <div className="space-y-2">
                                <textarea
                                  ref={editInputRef}
                                  className="input w-full text-sm resize-none"
                                  rows={3}
                                  value={editInput}
                                  onChange={(e) => setEditInput(e.target.value)}
                                  placeholder={t('chat.edit_placeholder')}
                                  disabled={editMutation.isPending}
                                />
                                <div className="flex gap-2">
                                  <button
                                    type="button"
                                    className="btn-primary px-3 py-1"
                                    onClick={() => editMutation.mutate({ messageId: msg.id, content: editInput.trim() })}
                                    disabled={!editInput.trim() || editMutation.isPending}
                                  >
                                    {t('common.action.save')}
                                  </button>
                                  <button type="button" className="btn-secondary px-3 py-1" onClick={cancelEdit}>
                                    {t('common.action.cancel')}
                                  </button>
                                </div>
                              </div>
                            ) : (
                              <p className="whitespace-pre-wrap">{msg.content}</p>
                            )}
                            <p className={`text-xs mt-1 ${msg.author === 'human' ? 'text-brand-200' : 'text-gray-400'}`}>
                              {formatTime(msg.createdAt)}
                              {isEdited && <span className="ml-2">{t('chat.edited_label')}</span>}
                              {isReply && <span className="ml-2 text-gray-400">{t('chat.reply_label')}</span>}
                            </p>
                          </div>
                          <div className="flex gap-3">
                            {canReply && (
                              <button
                                onClick={() => startReply(msg.id)}
                                className="flex items-center gap-1 mt-1 text-xs text-gray-400 hover:text-brand-600 transition-colors"
                              >
                                <Reply className="w-3 h-3" />
                                {t('chat.reply')}
                              </button>
                            )}
                            {isEditable && (
                              <button
                                onClick={() => startEdit(msg)}
                                className="flex items-center gap-1 mt-1 text-xs text-gray-400 hover:text-brand-600 transition-colors"
                              >
                                <Pencil className="w-3 h-3" />
                                {t('common.action.edit')}
                              </button>
                            )}
                          </div>
                          {replyingTo === msg.id && (
                            <form onSubmit={handleReply} className="mt-2 flex gap-2">
                              <textarea
                                ref={replyInputRef}
                                className="input flex-1 text-sm resize-none"
                                rows={2}
                                value={replyInput}
                                onChange={(e) => setReplyInput(e.target.value)}
                                placeholder={t('chat.reply_placeholder')}
                                disabled={replyMutation.isPending}
                              />
                              <div className="flex flex-col gap-1">
                                <button
                                  type="submit"
                                  className="btn-primary px-3 py-1 text-sm"
                                  disabled={!replyInput.trim() || replyMutation.isPending}
                                >
                                  <Send className="w-3 h-3" />
                                </button>
                                <button
                                  type="button"
                                  onClick={cancelReply}
                                  className="text-xs text-gray-400 hover:text-gray-600"
                                >
                                  {t('common.action.cancel')}
                                </button>
                              </div>
                            </form>
                          )}
                        </div>
                      </div>
                    )
                  })}
                </div>
              ))
            )}
            <div ref={bottomRef} />
          </div>

          <form onSubmit={handleSend} className="px-4 py-3 border-t border-gray-100 flex gap-2">
            <input
              className="input flex-1"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder={t('chat.input_placeholder')}
              disabled={sendMutation.isPending}
            />
            <button type="submit" className="btn-primary px-3" disabled={!input.trim() || sendMutation.isPending}>
              <Send className="w-4 h-4" />
            </button>
          </form>
        </div>
      )}
    </>
  )
}
