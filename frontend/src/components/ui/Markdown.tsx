/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import { Fragment, type ReactNode } from 'react'

type Block =
  | { type: 'heading'; level: number; content: string }
  | { type: 'paragraph'; content: string }
  | { type: 'list'; ordered: boolean; items: string[] }
  | { type: 'blockquote'; lines: string[] }
  | { type: 'code'; content: string }
  | { type: 'hr' }

function parseMarkdown(source: string, preserveLineBreaks = false): Block[] {
  const lines = source.replace(/\r\n/g, '\n').split('\n')
  const blocks: Block[] = []
  let i = 0

  while (i < lines.length) {
    const line = lines[i]
    const trimmed = line.trim()

    if (trimmed === '') {
      i += 1
      continue
    }

    if (trimmed.startsWith('```')) {
      const codeLines: string[] = []
      i += 1
      while (i < lines.length && !lines[i].trim().startsWith('```')) {
        codeLines.push(lines[i])
        i += 1
      }
      i += 1
      blocks.push({ type: 'code', content: codeLines.join('\n') })
      continue
    }

    if (/^#{1,6}\s/.test(trimmed)) {
      const [, hashes, content] = trimmed.match(/^(#{1,6})\s+(.*)$/) ?? []
      blocks.push({ type: 'heading', level: hashes.length, content })
      i += 1
      continue
    }

    if (/^(-{3,}|\*{3,}|_{3,})$/.test(trimmed)) {
      blocks.push({ type: 'hr' })
      i += 1
      continue
    }

    if (/^>\s?/.test(trimmed)) {
      const quoteLines: string[] = []
      while (i < lines.length && /^>\s?/.test(lines[i].trim())) {
        quoteLines.push(lines[i].trim().replace(/^>\s?/, ''))
        i += 1
      }
      blocks.push({ type: 'blockquote', lines: quoteLines })
      continue
    }

    if (/^\d+\.\s+/.test(trimmed) || /^[-*]\s+/.test(trimmed)) {
      const ordered = /^\d+\.\s+/.test(trimmed)
      const items: string[] = []
      while (i < lines.length) {
        const current = lines[i].trim()
        if (ordered && /^\d+\.\s+/.test(current)) {
          items.push(current.replace(/^\d+\.\s+/, ''))
          i += 1
          continue
        }
        if (!ordered && /^[-*]\s+/.test(current)) {
          items.push(current.replace(/^[-*]\s+/, ''))
          i += 1
          continue
        }
        break
      }
      blocks.push({ type: 'list', ordered, items })
      continue
    }

    const paragraphLines: string[] = []
    while (i < lines.length && lines[i].trim() !== '') {
      const current = lines[i].trim()
      if (/^(#{1,6}\s|```|>\s?|[-*]\s+|\d+\.\s+|-{3,}|\*{3,}|_{3,})/.test(current)) {
        break
      }
      paragraphLines.push(current)
      i += 1
    }
    blocks.push({ type: 'paragraph', content: paragraphLines.join(preserveLineBreaks ? '\n' : ' ') })
  }

  return blocks
}

function renderInline(text: string): ReactNode[] {
  const parts = text.split(/(`[^`]+`|\[[^\]]+\]\([^)]+\)|\*\*[^*]+\*\*|\*[^*]+\*)/g).filter(Boolean)
  return parts.map((part, index) => {
    if (part.startsWith('`') && part.endsWith('`')) {
      return (
        <code
          key={index}
          className="rounded-md border px-1.5 py-0.5 text-[0.92em] font-medium"
          style={{ background: 'color-mix(in srgb, var(--surface2) 80%, transparent)', borderColor: 'var(--border)', color: 'var(--text)' }}
        >
          {part.slice(1, -1)}
        </code>
      )
    }

    if (part.startsWith('[')) {
      const match = part.match(/^\[([^\]]+)\]\(([^)]+)\)$/)
      if (match) {
        return (
          <a
            key={index}
            href={match[2]}
            target="_blank"
            rel="noreferrer"
            style={{ color: 'var(--brand)' }}
            className="font-medium underline decoration-[0.08em] underline-offset-4"
          >
            {match[1]}
          </a>
        )
      }
    }

    if (part.startsWith('**') && part.endsWith('**')) {
      return <strong key={index}>{part.slice(2, -2)}</strong>
    }

    if (part.startsWith('*') && part.endsWith('*')) {
      return <em key={index}>{part.slice(1, -1)}</em>
    }

    return <Fragment key={index}>{part}</Fragment>
  })
}

function headingClass(level: number): string {
  if (level <= 1) return 'text-xl font-semibold tracking-tight'
  if (level === 2) return 'text-lg font-semibold tracking-tight'
  if (level === 3) return 'text-base font-semibold'
  return 'text-sm font-semibold uppercase tracking-[0.08em]'
}

function headingCompactClass(level: number): string {
  if (level <= 1) return 'text-lg font-semibold tracking-tight'
  if (level === 2) return 'text-base font-semibold tracking-tight'
  if (level === 3) return 'text-sm font-semibold'
  return 'text-[0.72rem] font-semibold uppercase tracking-[0.08em]'
}

/**
 * Renders a lightweight markdown subset with an optional compact density for dense UI surfaces.
 */
export default function Markdown({
  content,
  className = '',
  density = 'default',
  preserveLineBreaks = false,
}: {
  content: string
  className?: string
  density?: 'default' | 'compact'
  preserveLineBreaks?: boolean
}) {
  const blocks = parseMarkdown(content, preserveLineBreaks)
  const isCompact = density === 'compact'

  return (
    <div className={`${isCompact ? 'space-y-3' : 'space-y-4'} ${className}`}>
      {blocks.map((block, index) => {
        if (block.type === 'heading') {
          const HeadingTag = `h${Math.min(block.level + 1, 6)}` as keyof JSX.IntrinsicElements
          return (
            <HeadingTag
              key={index}
              className={isCompact ? headingCompactClass(block.level) : headingClass(block.level)}
              style={{ color: 'var(--text)' }}
            >
              {renderInline(block.content)}
            </HeadingTag>
          )
        }

        if (block.type === 'paragraph') {
          return (
            <p
              key={index}
              className={isCompact ? 'break-words whitespace-pre-wrap text-sm leading-6' : 'break-words whitespace-pre-wrap text-[0.95rem] leading-7'}
              style={{ color: 'var(--text)' }}
            >
              {renderInline(block.content)}
            </p>
          )
        }

        if (block.type === 'list') {
          const ListTag = block.ordered ? 'ol' : 'ul'
          return (
            <ListTag
              key={index}
              className={block.ordered
                ? isCompact
                  ? 'list-decimal pl-5 space-y-1.5 text-sm leading-6'
                  : 'list-decimal pl-6 space-y-2 text-[0.95rem] leading-7'
                : isCompact
                  ? 'list-disc pl-5 space-y-1.5 text-sm leading-6'
                  : 'list-disc pl-6 space-y-2 text-[0.95rem] leading-7'}
              style={{ color: 'var(--text)' }}
            >
              {block.items.map((item, itemIndex) => (
                <li key={itemIndex} className="pl-1 marker:text-[var(--brand)]">
                  {renderInline(item)}
                </li>
              ))}
            </ListTag>
          )
        }

        if (block.type === 'blockquote') {
          return (
            <blockquote
              key={index}
              className={isCompact ? 'rounded-r-xl border-l-4 px-3 py-2 italic text-sm leading-6' : 'rounded-r-xl border-l-4 px-4 py-3 italic'}
              style={{ borderColor: 'var(--brand)', background: 'color-mix(in srgb, var(--brand-dim) 18%, var(--surface) 82%)', color: 'var(--muted)' }}
            >
              {block.lines.map((line, lineIndex) => (
                <p key={lineIndex} className={lineIndex > 0 ? 'mt-2' : ''}>
                  {renderInline(line)}
                </p>
              ))}
            </blockquote>
          )
        }

        if (block.type === 'code') {
          return (
            <pre
              key={index}
              className={isCompact ? 'overflow-x-auto rounded-xl border p-3 text-xs leading-5' : 'overflow-x-auto rounded-2xl border p-4 text-sm leading-6'}
              style={{ background: 'color-mix(in srgb, var(--surface2) 88%, transparent)', borderColor: 'var(--border)', color: 'var(--text)' }}
            >
              <code>{block.content}</code>
            </pre>
          )
        }

        return <hr key={index} className={isCompact ? 'my-4' : 'my-6'} style={{ borderColor: 'var(--border)' }} />
      })}
    </div>
  )
}
