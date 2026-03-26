import { useState, useRef, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Send, Bot, User } from 'lucide-react'
import { chatApi } from '@/api/chat'
import { projectsApi } from '@/api/projects'
import { agentsApi } from '@/api/agents'
import { PageSpinner } from '@/components/ui/Spinner'
import PageHeader from '@/components/ui/PageHeader'

function fmt(date: string) {
  return new Date(date).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

export default function ChatPage() {
  const qc = useQueryClient()
  const [projectId, setProjectId] = useState('')
  const [agentId, setAgentId]     = useState('')
  const [input, setInput]         = useState('')
  const bottomRef = useRef<HTMLDivElement>(null)

  const { data: projects } = useQuery({ queryKey: ['projects'], queryFn: projectsApi.list })
  const { data: agents }   = useQuery({ queryKey: ['agents'], queryFn: agentsApi.list })

  const { data: messages, isLoading } = useQuery({
    queryKey: ['chat', projectId, agentId],
    queryFn: () => chatApi.history(projectId, agentId),
    enabled: !!(projectId && agentId),
    refetchInterval: 5000,
  })

  const sendMutation = useMutation({
    mutationFn: (content: string) => chatApi.send(projectId, agentId, content),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['chat', projectId, agentId] })
      setInput('')
    },
  })

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [messages])

  const handleSend = (e: React.FormEvent) => {
    e.preventDefault()
    if (input.trim()) sendMutation.mutate(input.trim())
  }

  const selectedAgent = agents?.find((a) => a.id === agentId)

  return (
    <>
      <PageHeader title="Chat" description="Échangez avec les agents IA au sein d'un projet." />

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
        <div className="card flex flex-col" style={{ height: '60vh' }}>
          {/* En-tête */}
          <div className="px-4 py-3 border-b border-gray-100 flex items-center gap-2">
            <Bot className="w-4 h-4 text-brand-600" />
            <span className="text-sm font-medium text-gray-900">{selectedAgent?.name}</span>
            {selectedAgent?.role && <span className="badge-blue text-xs">{selectedAgent.role.name}</span>}
          </div>

          {/* Messages */}
          <div className="flex-1 overflow-y-auto px-4 py-4 space-y-3">
            {isLoading ? <PageSpinner /> : messages?.length === 0 ? (
              <p className="text-center text-sm text-gray-400 mt-8">Aucun message — démarrez la conversation.</p>
            ) : (
              messages?.map((msg) => (
                <div key={msg.id} className={`flex gap-2 ${msg.author === 'human' ? 'flex-row-reverse' : ''}`}>
                  <div className={`w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 ${msg.author === 'human' ? 'bg-brand-600' : 'bg-gray-200'}`}>
                    {msg.author === 'human' ? <User className="w-3.5 h-3.5 text-white" /> : <Bot className="w-3.5 h-3.5 text-gray-600" />}
                  </div>
                  <div className={`max-w-[75%] rounded-xl px-3 py-2 text-sm ${msg.author === 'human' ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-800'}`}>
                    <p className="whitespace-pre-wrap">{msg.content}</p>
                    <p className={`text-xs mt-1 ${msg.author === 'human' ? 'text-brand-200' : 'text-gray-400'}`}>{fmt(msg.createdAt)}</p>
                  </div>
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
