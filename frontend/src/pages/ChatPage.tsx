/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { useState, useRef, useEffect, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Send, Bot, User, Reply } from 'lucide-react'
import { chatApi } from '@/api/chat'
import { translationsApi } from '@/api/translations'
import { projectsApi } from '@/api/projects'
import { agentsApi } from '@/api/agents'
import { PageSpinner } from '@/components/ui/Spinner'
import PageHeader from '@/components/ui/PageHeader'
import ContentLoadingOverlay from '@/components/ui/ContentLoadingOverlay'
import type { ChatMessage } from '@/types'

function fmt(date: string) {
  return new Date(date).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

interface MessageGroup {
  exchangeId: string
  messages: ChatMessage[]
  parentId: string | null
}

export default function ChatPage() {
  const qc = useQueryClient()
  const [projectId, setProjectId] = useState('')
  const [agentId, setAgentId]     = useState('')
  const [input, setInput]         = useState('')
  const [replyingTo, setReplyingTo] = useState<string | null>(null)
  const [replyInput, setReplyInput] = useState('')
  const bottomRef = useRef<HTMLDivElement>(null)
  const replyInputRef = useRef<HTMLTextAreaElement>(null)

  const { data: projects } = useQuery({ queryKey: ['projects'], queryFn: projectsApi.list })
  const { data: agents }   = useQuery({ queryKey: ['agents'], queryFn: agentsApi.list })

  const { data: messages, isLoading, isFetching } = useQuery({
    queryKey: ['chat', projectId, agentId],
    queryFn: () => chatApi.history(projectId, agentId),
    enabled: !!(projectId && agentId),
    refetchInterval: 5000,
  })

  const { data: chatI18n } = useQuery({
    queryKey: ['ui-translations', 'chat'],
    queryFn: () => translationsApi.list([
      'chat.item.loading',
      'common.action.refresh',
      'chat.reply',
      'chat.reply_label',
      'chat.reply_placeholder',
      'chat.send_reply',
    ]),
  })
  const tt = (key: string) => chatI18n?.translations[key] ?? key

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

  const groupedMessages = useMemo(() => {
    if (!messages) return []

    const groups: Map<string, MessageGroup> = new Map()

    for (const msg of messages) {
      const exchangeId = msg.exchangeId

      if (!groups.has(exchangeId)) {
        groups.set(exchangeId, {
          exchangeId,
          messages: [],
          parentId: msg.replyToMessageId ?? null,
        })
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
    setReplyingTo(messageId)
    setReplyInput('')
  }

  const cancelReply = () => {
    setReplyingTo(null)
    setReplyInput('')
  }

  const selectedAgent = agents?.find((a) => a.id === agentId)

  return (
    <>
      <PageHeader title="Chat" description="Échangez avec les agents IA au sein d'un projet."
        onRefresh={() => { qc.invalidateQueries({ queryKey: ['projects'] }); qc.invalidateQueries({ queryKey: ['agents'] }); qc.invalidateQueries({ queryKey: ['chat'] }) }}
        refreshTitle={tt('common.action.refresh')} />

      <div className="grid grid-cols-2 gap-4 mb-6">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Projet</label>
          <select className="input" value={projectId} onChange={(e) => { setProjectId(e.target.value); setAgentId('') }}>
            <option value="">— Sélectionner —</option>
            {projects?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Agent</label>
          <select className="input" value={agentId} onChange={(e) => setAgentId(e.target.value)} disabled={!projectId}>
            <option value="">— Sélectionner —</option>
            {agents?.map((a) => <option key={a.id} value={a.id}>{a.name}{a.role ? ` — ${a.role.name}` : ''}</option>)}
          </select>
        </div>
      </div>

      {!(projectId && agentId) ? (
        <div className="card p-8 text-center text-gray-400">
          <Bot className="w-12 h-12 mx-auto mb-3 opacity-30" />
          <p className="text-sm">Sélectionnez un projet et un agent pour démarrer la conversation.</p>
        </div>
      ) : (
        <div className="relative card flex flex-col" style={{ height: '60vh' }}>
          <ContentLoadingOverlay isLoading={isFetching && !isLoading} label={tt('chat.item.loading')} />
          {/* En-tête */}
          <div className="px-4 py-3 border-b border-gray-100 flex items-center gap-2">
            <Bot className="w-4 h-4 text-brand-600" />
            <span className="text-sm font-medium text-gray-900">{selectedAgent?.name}</span>
            {selectedAgent?.role && <span className="badge-blue text-xs">{selectedAgent.role.name}</span>}
          </div>

          {/* Messages */}
          <div className="list-chat-message flex-1 overflow-y-auto px-4 py-4 space-y-4">
            {isLoading ? <PageSpinner /> : groupedMessages.length === 0 ? (
              <p className="text-center text-sm text-gray-400 mt-8">Aucun message — démarrez la conversation.</p>
            ) : (
              groupedMessages.map((group) => (
                <div key={group.exchangeId} className="space-y-2">
                  {group.messages.map((msg, idx) => {
                    const isReply = msg.replyToMessageId !== null
                    const canReply = idx === 0 && !isReply

                    return (
                      <div key={msg.id} className={`item-chat-message flex gap-2 ${msg.author === 'human' ? 'flex-row-reverse' : ''}`}>
                        <div className={`flex flex-col items-center`}>
                          <div className={`w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 ${msg.author === 'human' ? 'bg-brand-600' : 'bg-gray-200'}`}>
                            {msg.author === 'human' ? <User className="w-3.5 h-3.5 text-white" /> : <Bot className="w-3.5 h-3.5 text-gray-600" />}
                          </div>
                        </div>
                        <div className={`flex-1 max-w-[75%] ${isReply ? 'ml-8' : ''}`}>
                          <div className={`rounded-xl px-3 py-2 text-sm ${isReply ? 'bg-gray-50 border border-gray-100' : ''} ${msg.author === 'human' ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-800'}`}>
                            <p className="whitespace-pre-wrap">{msg.content}</p>
                            <p className={`text-xs mt-1 ${msg.author === 'human' ? 'text-brand-200' : 'text-gray-400'}`}>
                              {fmt(msg.createdAt)}
                              {isReply && <span className="ml-2 text-gray-400">{tt('chat.reply_label')}</span>}
                            </p>
                          </div>
                          {canReply && (
                            <button
                              onClick={() => startReply(msg.id)}
                              className="flex items-center gap-1 mt-1 text-xs text-gray-400 hover:text-brand-600 transition-colors"
                            >
                              <Reply className="w-3 h-3" />
                              {tt('chat.reply')}
                            </button>
                          )}
                          {replyingTo === msg.id && (
                            <form onSubmit={handleReply} className="mt-2 flex gap-2">
                              <textarea
                                ref={replyInputRef}
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
                                  {tt('common.action.cancel')}
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

          {/* Saisie */}
          <form onSubmit={handleSend} className="px-4 py-3 border-t border-gray-100 flex gap-2">
            <input
              className="input flex-1"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder="Écrivez votre message…"
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
