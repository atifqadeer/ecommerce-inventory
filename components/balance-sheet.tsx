'use client'

import { useState } from 'react'
import { ArrowDownRight, ArrowUpRight, BookOpen, CalendarDays, CheckCircle2, ChevronRight, X } from 'lucide-react'

const assetRows = [
  { label: 'Cash', value: 48260, detail: 'Operating cash accounts' },
  { label: 'Marketplace receivables', value: 126840, detail: 'Amounts owed by connected channels', children: [
    { label: 'Shopify receivables', value: 48200 }, { label: 'Amazon receivables', value: 61240 }, { label: 'Etsy receivables', value: 9840 }, { label: 'eBay receivables', value: 17560 },
  ] },
  { label: 'Inventory value', value: 214680, detail: 'Weighted average cost across warehouses' },
  { label: 'Other assets', value: 12600, detail: 'Prepaid expenses and deposits' },
]
const liabilityRows = [
  { label: 'Supplier payables', value: 68420, detail: 'Open bills and purchase orders' },
  { label: 'Sales tax payable', value: 18360, detail: 'Collected tax awaiting remittance' },
  { label: "Owner's equity", value: 142000, detail: 'Capital contributions' },
  { label: 'Retained earnings', value: 173600, detail: 'Cumulative profits less distributions' },
]
const journalEntries = [
  { id: 'JE-1048', date: 'Jun 30, 2024', description: 'Marketplace settlement batch', debit: '$61,240', credit: '$61,240' },
  { id: 'JE-1041', date: 'Jun 28, 2024', description: 'Inventory receipt · PO-8842', debit: '$18,640', credit: '$18,640' },
  { id: 'JE-1037', date: 'Jun 26, 2024', description: 'Supplier invoice · SUP-2201', debit: '$12,800', credit: '$12,800' },
]
const money = (value: number) => `$${value.toLocaleString('en-US', { maximumFractionDigits: 0 })}`

type Row = { label: string; value: number; detail: string; children?: { label: string; value: number }[] }

export default function BalanceSheet() {
  const [date, setDate] = useState('2024-06-30')
  const [expanded, setExpanded] = useState<string[]>([])
  const [selected, setSelected] = useState<Row | null>(null)
  const assets = assetRows.reduce((sum, row) => sum + row.value, 0)
  const liabilities = liabilityRows.slice(0, 2).reduce((sum, row) => sum + row.value, 0)
  const equity = liabilityRows.slice(2).reduce((sum, row) => sum + row.value, 0)
  const balanced = assets === liabilities + equity
  const toggle = (label: string) => setExpanded((items) => items.includes(label) ? items.filter((item) => item !== label) : [...items, label])
  const formattedDate = new Date(`${date}T12:00:00`).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })

  return <div className="flex flex-col gap-6">
    <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div><p className="text-sm font-medium text-indigo-600">Finance</p><h1 className="mt-1 text-2xl font-semibold tracking-tight">Balance Sheet</h1><p className="mt-1 text-sm text-muted-foreground">Financial position as of {formattedDate}</p></div>
      <label className="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm"><CalendarDays className="size-4 text-muted-foreground" /><span className="text-muted-foreground">As of</span><input aria-label="As of date" type="date" value={date} onChange={(event) => setDate(event.target.value)} className="bg-transparent font-medium outline-none" /></label>
    </div>

    <section className={`flex items-center gap-3 rounded-xl border px-4 py-3 ${balanced ? 'border-emerald-200 bg-emerald-50/70 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800'}`}><CheckCircle2 className="size-5" /><div><p className="text-sm font-semibold">{balanced ? 'Balance sheet is balanced' : 'Balance sheet needs review'}</p><p className="text-xs opacity-80">Assets {money(assets)} = Liabilities &amp; Equity {money(liabilities + equity)}</p></div><span className="ml-auto text-sm font-semibold">{balanced ? '✓ $0 difference' : `${money(assets - liabilities - equity)} difference`}</span></section>

    <div className="grid gap-6 xl:grid-cols-2">
      <BalanceColumn title="Assets" subtitle="What the business owns" rows={assetRows} total={assets} expanded={expanded} onToggle={toggle} onSelect={setSelected} />
      <BalanceColumn title="Liabilities & Equity" subtitle="What the business owes and owner value" rows={liabilityRows} total={liabilities + equity} expanded={expanded} onToggle={toggle} onSelect={setSelected} />
    </div>

    <div className="flex items-center gap-2 text-xs text-muted-foreground"><BookOpen className="size-3.5" /> Click any line item to inspect the journal entries behind the balance.</div>
    {selected && <div className="fixed inset-0 z-40 bg-black/20" onClick={() => setSelected(null)}><aside role="dialog" aria-label={`${selected.label} journal entries`} className="absolute inset-y-0 right-0 flex w-full max-w-md flex-col border-l border-border bg-card shadow-2xl" onClick={(event) => event.stopPropagation()}><div className="flex items-start justify-between border-b border-border p-5"><div><p className="text-xs font-medium text-indigo-600">Journal drill-down</p><h2 className="mt-1 text-lg font-semibold">{selected.label}</h2><p className="mt-1 text-sm text-muted-foreground">{selected.detail}</p></div><button aria-label="Close journal drawer" onClick={() => setSelected(null)} className="rounded-md p-1.5 text-muted-foreground hover:bg-muted"><X className="size-5" /></button></div><div className="border-b border-border bg-muted/30 p-5"><p className="text-xs text-muted-foreground">Balance at {formattedDate}</p><p className="mt-1 text-2xl font-semibold">{money(selected.value)}</p></div><div className="flex-1 overflow-y-auto p-5"><h3 className="text-sm font-semibold">Journal entries</h3><div className="mt-5 flex flex-col gap-5">{journalEntries.map((entry) => <div key={entry.id} className="relative border-l-2 border-indigo-200 pl-5"><span className="absolute -left-[7px] top-0 size-3 rounded-full border-2 border-card bg-indigo-500" /><p className="text-xs font-medium text-indigo-600">{entry.date} · {entry.id}</p><p className="mt-1 text-sm font-medium">{entry.description}</p><div className="mt-2 flex gap-4 text-xs text-muted-foreground"><span>Debit <b className="text-foreground">{entry.debit}</b></span><span>Credit <b className="text-foreground">{entry.credit}</b></span></div></div>)}</div></div></aside></div>}
  </div>
}

function BalanceColumn({ title, subtitle, rows, total, expanded, onToggle, onSelect }: { title: string; subtitle: string; rows: Row[]; total: number; expanded: string[]; onToggle: (label: string) => void; onSelect: (row: Row) => void }) {
  return <section className="overflow-hidden rounded-xl border border-border bg-card"><div className="border-b border-border p-5"><h2 className="font-semibold">{title}</h2><p className="mt-1 text-xs text-muted-foreground">{subtitle}</p></div><div className="divide-y divide-border">{rows.map((row) => <div key={row.label}><button onClick={() => onSelect(row)} className="flex w-full items-center gap-3 px-5 py-4 text-left hover:bg-muted/40"><span className="flex size-8 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600"><ArrowUpRight className="size-4" /></span><span className="min-w-0 flex-1"><span className="block text-sm font-medium">{row.label}</span><span className="mt-0.5 block text-xs text-muted-foreground">{row.detail}</span></span>{row.children && <span role="button" aria-label={`Expand ${row.label}`} onClick={(event) => { event.stopPropagation(); onToggle(row.label) }} className="rounded p-1 text-muted-foreground hover:bg-muted"><ChevronRight className={`size-4 transition-transform ${expanded.includes(row.label) ? 'rotate-90' : ''}`} /></span>}<span className="text-sm font-semibold">{money(row.value)}</span></button>{row.children && expanded.includes(row.label) && <div className="bg-muted/20 px-5 pb-3 pl-16">{row.children.map((child) => <button key={child.label} onClick={() => onSelect({ ...child, detail: 'Channel receivable journal activity' })} className="flex w-full items-center justify-between border-t border-border/60 py-2.5 text-left text-sm"><span className="text-muted-foreground">{child.label}</span><span className="font-medium">{money(child.value)}</span></button>)}</div>}</div>)}<div className="flex items-center justify-between bg-muted/40 px-5 py-4"><span className="text-sm font-semibold">Total {title}</span><span className="text-lg font-semibold">{money(total)}</span></div></div></section>
}
