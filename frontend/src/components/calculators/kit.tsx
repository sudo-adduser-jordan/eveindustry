import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import type { Structure } from '../../api/calculators'
import { ApiError } from '../../api/client'
import './calculators.css'
import { typeIconUrl, iconFallback } from '../typeIcon'

export const DEFAULT_REGION = '10000002'

export const PRICE_REGIONS = [
  ['10000002', 'The Forge (Jita)'],
  ['10000043', 'Domain (Amarr)'],
  ['10000032', 'Sinq Laison (Dodixie)'],
  ['10000030', 'Heimatar (Rens)'],
  ['10000042', 'Metropolis (Hek)'],
] as const


export function TypeIcon({ typeId, size = 24 }: { typeId: number; size?: number }) {
  return (
    <img
      src={typeIconUrl(typeId)}
      alt=""
      width={size}
      height={size}
      loading="lazy"
      onError={iconFallback}
    />
  )
}


export function Field({
  id,
  label,
  hint,
  children,
}: {
  id: string
  label: string
  hint?: string
  children: ReactNode
}) {
  return (
    <div className="scan-field">
      <label htmlFor={id}>
        {label} {hint ? <span className="muted">{hint}</span> : null}
      </label>
      {children}
    </div>
  )
}


export function NumberField({
  label,
  hint,
  value,
  onChange,
  min,
  max,
  step,
  placeholder,
}: {
  label: string
  hint?: string
  value: string
  onChange: (value: string) => void
  min?: number
  max?: number
  step?: number
  placeholder?: string
}) {
  const id = useId()
  return (
    <Field id={id} label={label} hint={hint}>
      <input
        id={id}
        type="number"
        inputMode="decimal"
        value={value}
        min={min}
        max={max}
        step={step}
        placeholder={placeholder}
        onChange={(e) => onChange(e.target.value)}
      />
    </Field>
  )
}


export function SelectField<V extends string>({
  label,
  hint,
  value,
  onChange,
  options,
}: {
  label: string
  hint?: string
  value: V
  onChange: (value: V) => void
  options: ReadonlyArray<readonly [V, string]>
}) {
  const id = useId()
  return (
    <Field id={id} label={label} hint={hint}>
      <select id={id} value={value} onChange={(e) => onChange(e.target.value as V)}>
        {options.map(([v, text]) => (
          <option key={v} value={v}>
            {text}
          </option>
        ))}
      </select>
    </Field>
  )
}


export function num(value: string, fallback: number): number {
  const n = Number(value)
  return value.trim() === '' || !Number.isFinite(n) ? fallback : n
}


export function intIn(value: string, min: number, max: number, fallback: number): number {
  return Math.min(max, Math.max(min, Math.round(num(value, fallback))))
}


export function SearchPicker<T>({
  label,
  placeholder,
  useResults,
  getKey,
  renderItem,
  onSelect,
  emptyText = 'Nothing found',
}: {
  label: string
  placeholder: string
  useResults: (q: string) => { data: T[] | undefined; isFetching: boolean; isError: boolean }
  getKey: (item: T) => string | number
  renderItem: (item: T) => ReactNode
  onSelect: (item: T) => void
  emptyText?: string
}) {
  const id = useId()
  const [text, setText] = useState('')
  const [settled, setSettled] = useState('')
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const t = setTimeout(() => setSettled(text.trim()), 300)
    return () => clearTimeout(t)
  }, [text])

  useEffect(() => {
    function close(e: MouseEvent) {
      if (box.current && !box.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [])

  const results = useResults(settled)
  const searching = settled.length >= 2

  return (
    <div className="scan-field calc-search" ref={box}>
      <label htmlFor={id}>{label}</label>
      <input
        id={id}
        type="search"
        autoComplete="off"
        placeholder={placeholder}
        value={text}
        onChange={(e) => {
          setText(e.target.value)
          setOpen(true)
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={(e) => {
          if (e.key === 'Escape') setOpen(false)
        }}
      />
      {open && searching ? (
        <ul className="calc-results" role="listbox" aria-label={`${label} results`}>
          {results.isFetching && !results.data ? <li className="muted">Searching…</li> : null}
          {results.isError ? <li className="muted">Search failed</li> : null}
          {results.data && results.data.length === 0 ? <li className="muted">{emptyText}</li> : null}
          {results.data?.map((item) => (
            <li key={getKey(item)} role="option" aria-selected="false">
              <button className="option"
                type="button"
                onClick={() => {
                  onSelect(item)
                  setText('')
                  setSettled('')
                  setOpen(false)
                }}
              >
                {renderItem(item)}
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}


export function StructureSelect({
  label,
  structures,
  value,
  onChange,
  noneLabel,
  hint,
}: {
  label: string
  structures: Structure[]
  value: number | null
  onChange: (id: number | null) => void
  noneLabel: string
  hint?: string
}) {
  const id = useId()
  const chosen = structures.find((s) => s.id === value)
  return (
    <div className="scan-field">
      <label htmlFor={id}>
        {label} {hint ? <span className="muted">{hint}</span> : null}
      </label>
      <select id={id} value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}>
        <option value="">{noneLabel}</option>
        {structures.map((s) => (
          <option key={s.id} value={s.id}>
            {s.name}
            {s.is_default ? ' ★' : ''}
          </option>
        ))}
      </select>
      {chosen && (chosen.effective_bonuses.me > 0 || chosen.effective_bonuses.te > 0) ? (
        <dl className="calc-bonus">
          <div>
            <dt>Effective ME bonus</dt>
            <dd>{chosen.effective_bonuses.me.toFixed(2)}%</dd>
          </div>
          {chosen.activity_type === 'reaction' ? null : (
            <div>
              <dt>Effective TE bonus</dt>
              <dd>{chosen.effective_bonuses.te.toFixed(2)}%</dd>
            </div>
          )}
        </dl>
      ) : null}
      <a className="small muted" href="/structures">
        Manage structures
      </a>
    </div>
  )
}


export function CopyButton({ label, onCopy, disabled }: { label: string; onCopy: () => string | null; disabled?: boolean }) {
  const [done, setDone] = useState(false)
  useEffect(() => {
    if (!done) return
    const t = setTimeout(() => setDone(false), 2000)
    return () => clearTimeout(t)
  }, [done])
  return (
    <button
      type="button"
      className="button"
      disabled={disabled}
      onClick={() => {
        const text = onCopy()
        if (text === null) return
        void navigator.clipboard.writeText(text).then(() => setDone(true))
      }}
    >
      {done ? 'Copied!' : label}
    </button>
  )
}


export function downloadText(filename: string, text: string, type = 'text/csv;charset=utf-8;') {
  const url = URL.createObjectURL(new Blob([text], { type }))
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}


export function Stat({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
  return (
    <div className="calc-stat">
      <dt>{label}</dt>
      <dd className={className}>{children}</dd>
    </div>
  )
}


export function errorText(error: Error): string {
  const errors = error instanceof ApiError ? error.errors : undefined
  return errors ? Object.values(errors).flat().join(' ') : error.message
}


export function pick<T extends string>(value: string | undefined | null, allowed: readonly T[], fallback: T): T {
  return allowed.find((a) => a === value) ?? fallback
}
