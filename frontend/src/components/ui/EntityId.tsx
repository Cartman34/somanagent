interface EntityIdProps {
  id: string
  prefix?: string
  className?: string
}

export default function EntityId({ id, prefix = 'ID', className = '' }: EntityIdProps) {
  return (
    <span
      className={`inline-flex max-w-full items-center rounded border px-1.5 py-0.5 font-mono text-[11px] ${className}`.trim()}
      style={{ color: 'var(--muted)', borderColor: 'var(--border)', background: 'var(--surface2)' }}
      title={`${prefix}: ${id}`}
    >
      {prefix}: {id}
    </span>
  )
}
