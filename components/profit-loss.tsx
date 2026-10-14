'use client'

import { useMemo, useState } from 'react'
import { BarChart3, ChevronDown, ChevronRight, Download, FileSpreadsheet, FileText, Info, MoreHorizontal, TrendingDown, TrendingUp } from 'lucide-react'

const channels = [
  { name: 'Shopify', color: 'bg-emerald-500', revenue: 84200, fees: 8420, shipping: 4780, ads: 3200, refunds: 1260, cogs: 26400 },
  { name: 'Amazon', color: 'bg-orange-500', revenue: 68400, fees: 12310, shipping: 6840, ads: 4100, refunds: 1840, cogs: 23900 },
  { name: 'Etsy', color: 'bg-rose-500', revenue: 18900, fees: 1700, shipping: 1560, ads: 980, refunds: 410, cogs: 6200 },
  { name: 'eBay', color: 'bg-blue-500', revenue: 12700, fees: 1410, shipping: 980, ads: 520, refunds: 230, cogs: 4100 },
]

const expenses = [
  { label: 'Marketplace fees', key: 'fees' as const, values: [8420, 12310, 1700, 1410] },
  { label: 'Payment processing', values: [2526, 2052, 567, 381] },
  { label: 'Shipping', key: 'shipping' as const, values: [4780, 6840, 1560, 980] },
  { label: 'Advertising', key: 'ads' as const, values: [3200, 4100, 980, 520] },
  { label: 'Refunds', key: 'refunds' as const, values: [1260, 1840, 410, 230] },
]

const money = (value: number) => `$${Math.abs(value).toLocaleString('en-US', { maximumFractionDigits: 0 })}`
const signedMoney = (value: number) => `${value < 0 ? '-' : ''}${money(value)}`

function Variance({ value }: { value: string }) {
  const positive = value.startsWith('+')
  return <span className={`inline-flex items-center gap-1 text-xs font-semibold ${positive ? 'text-emerald-600' : 'text-rose-600'}`}>{positive ? <TrendingUp className="size-3" /> : <TrendingDown className="size-3" />}{value}</span>
}

export default function ProfitLoss() {
  const [view, setView] = useState('By channel')
  const [expanded, setExpanded] = useState<string[]>(['revenue', 'cogs', 'opex'])
  const toggle = (key: string) => setExpanded((items) => items.includes(key) ? items.filter((item) => item !== key) : [...items, key])
  const totals = useMemo(() => channels.reduce((acc, channel) => ({ revenue: acc.revenue + channel.revenue, cogs: acc.cogs + channel.cogs, fees: acc.fees + channel.fees, shipping: acc.shipping + channel.shipping, ads: acc.ads + channel.ads, refunds: acc.refunds + channel.refunds }), { revenue: 0, cogs: 0, fees: 0, shipping: 0, ads: 0, refunds: 0 }), [])
  const gross = totals.revenue - totals.cogs
  const opex = totals.fees + totals.shipping + totals.ads + totals.refunds + 5526
  const net = gross - opex

  return <div className="flex flex-col gap-6">
    <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div><p className="text-sm font-medium text-indigo-600">Finance</p><h1 className="mt-1 text-2xl font-semibold tracking-tight">Profit &amp; Loss</h1><p className="mt-1 text-sm text-muted-foreground">June 1 – June 30, 2024 · Compared with May 2024</p></div>
      <div className="flex flex-wrap items-center gap-2"><button className="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium hover:bg-muted"><FileText className="size-4" /> Export PDF</button><button className="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium hover:bg-muted"><FileSpreadsheet className="size-4" /> Export Excel</button><button className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Close period</button></div>
    </div>

    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><Metric label="Gross revenue" value={money(totals.revenue)} variance="+12.8%" /><Metric label="Gross profit" value={money(gross)} variance="+9.4%" /><Metric label="Operating expenses" value={money(opex)} variance="-4.2%" negative /><Metric label="Net profit" value={money(net)} variance="+18.6%" highlight /></div>

    <div className="grid gap-6 xl:grid-cols-[1.55fr_1fr]">
      <section className="overflow-hidden rounded-xl border border-border bg-card"><div className="flex flex-col gap-3 border-b border-border p-5 sm:flex-row sm:items-center sm:justify-between"><div><h2 className="font-semibold">P&amp;L statement</h2><p className="mt-1 text-xs text-muted-foreground">Accrual basis · USD</p></div><div className="flex rounded-lg bg-muted p-1">{['By channel', 'By month', 'By SKU'].map((option) => <button key={option} onClick={() => setView(option)} className={`rounded-md px-3 py-1.5 text-xs font-medium transition ${view === option ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground'}`}>{option}</button>)}</div></div>
        <div className="overflow-x-auto"><table className="w-full min-w-[760px] text-sm"><thead className="bg-muted/40 text-left text-xs text-muted-foreground"><tr><th className="px-5 py-3 font-medium">Line item</th>{channels.map((channel) => <th key={channel.name} className="px-3 py-3 text-right font-medium">{channel.name}</th>)}<th className="px-4 py-3 text-right font-semibold text-foreground">Total</th><th className="px-4 py-3 text-right font-medium">vs prev.</th></tr></thead><tbody>
          <StatementRow label="Revenue" values={channels.map(c => c.revenue)} total={totals.revenue} variance="+12.8%" open={expanded.includes('revenue')} onClick={() => toggle('revenue')} bold />
          {expanded.includes('revenue') && <StatementRow label="Gross sales" values={channels.map(c => c.revenue)} total={totals.revenue} variance="+12.8%" indent />}
          <StatementRow label="Cost of Goods Sold" values={channels.map(c => -c.cogs)} total={-totals.cogs} variance="-3.1%" open={expanded.includes('cogs')} onClick={() => toggle('cogs')} bold negative />
          {expanded.includes('cogs') && <StatementRow label="Product costs" values={channels.map(c => -c.cogs)} total={-totals.cogs} variance="-3.1%" indent negative />}
          <StatementRow label="Gross Profit" values={channels.map(c => c.revenue - c.cogs)} total={gross} variance="+9.4%" bold highlightRow />
          <StatementRow label="Operating Expenses" values={channels.map((_, i) => -expenses.reduce((sum, expense) => sum + expense.values[i], 0) - 1381.5)} total={-opex} variance="-4.2%" open={expanded.includes('opex')} onClick={() => toggle('opex')} bold negative />
          {expanded.includes('opex') && expenses.map((expense) => <StatementRow key={expense.label} label={expense.label} values={expense.values.map(value => -value)} total={-expense.values.reduce((a, b) => a + b, 0)} variance={expense.label === 'Advertising' ? '+6.2%' : '-2.4%'} indent negative />)}
          {expanded.includes('opex') && <StatementRow label="Software &amp; admin" values={[-1381.5, -1381.5, -1381.5, -1381.5]} total={-5526} variance="-8.0%" indent negative />}
          <StatementRow label="Net Profit" values={channels.map((c, i) => c.revenue - c.cogs - expenses.reduce((sum, expense) => sum + expense.values[i], 0) - 1381.5)} total={net} variance="+18.6%" bold highlightRow />
        </tbody></table></div>
      </section>

      <section className="rounded-xl border border-border bg-card p-5"><div className="flex items-start justify-between"><div><h2 className="font-semibold">Profit waterfall</h2><p className="mt-1 text-xs text-muted-foreground">How revenue becomes net profit</p></div><button aria-label="More chart options" className="rounded-md p-1.5 text-muted-foreground hover:bg-muted"><MoreHorizontal className="size-4" /></button></div><div className="mt-6 flex h-56 items-end gap-2 border-b border-l border-border px-3 pb-0 pt-6">{[{ label: 'Revenue', value: totals.revenue, color: 'bg-indigo-500', height: 100 }, { label: 'COGS', value: totals.cogs, color: 'bg-rose-400', height: 61 }, { label: 'Fees', value: totals.fees, color: 'bg-amber-400', height: 18 }, { label: 'Shipping', value: totals.shipping, color: 'bg-amber-300', height: 13 }, { label: 'Ads', value: totals.ads, color: 'bg-amber-200', height: 8 }, { label: 'Net profit', value: net, color: 'bg-emerald-500', height: 41 }].map((bar) => <div key={bar.label} className="flex min-w-0 flex-1 flex-col items-center justify-end gap-2"><span className="text-[10px] font-semibold text-muted-foreground">{money(bar.value)}</span><div className={`w-full rounded-t-md ${bar.color}`} style={{ height: `${bar.height}%` }} /><span className="truncate text-[10px] text-muted-foreground">{bar.label}</span></div>)}</div><div className="mt-5 flex items-center justify-between rounded-lg bg-muted/50 px-3 py-3"><div><p className="text-xs text-muted-foreground">Net margin</p><p className="mt-0.5 text-lg font-semibold">{((net / totals.revenue) * 100).toFixed(1)}%</p></div><div className="text-right"><p className="text-xs text-muted-foreground">Previous period</p><p className="mt-0.5 text-sm font-semibold text-emerald-600">+2.1 pts</p></div></div></section>
    </div>
    <div className="flex items-center gap-2 text-xs text-muted-foreground"><Info className="size-3.5" /> Values are calculated from synced order, fee, shipping, and product cost data. Last synced 8 minutes ago.</div>
  </div>
}

function Metric({ label, value, variance, negative, highlight }: { label: string; value: string; variance: string; negative?: boolean; highlight?: boolean }) { return <section className={`rounded-xl border p-5 ${highlight ? 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-900 dark:bg-emerald-950/20' : 'border-border bg-card'}`}><p className="text-sm text-muted-foreground">{label}</p><div className="mt-3 flex items-end justify-between gap-2"><p className="text-2xl font-semibold tracking-tight">{value}</p><span className={`text-xs font-semibold ${negative ? 'text-rose-600' : 'text-emerald-600'}`}>{variance}</span></div><div className="mt-3 h-1.5 overflow-hidden rounded-full bg-muted"><div className={`h-full rounded-full ${negative ? 'bg-rose-400' : 'bg-emerald-500'}`} style={{ width: negative ? '46%' : highlight ? '78%' : '68%' }} /></div></section> }

function StatementRow({ label, values, total, variance, open, onClick, indent, bold, negative, highlightRow }: { label: string; values: number[]; total: number; variance: string; open?: boolean; onClick?: () => void; indent?: boolean; bold?: boolean; negative?: boolean; highlightRow?: boolean }) { return <tr className={`${highlightRow ? 'border-y border-border bg-muted/35' : 'border-b border-border/70'} ${onClick ? 'cursor-pointer hover:bg-muted/30' : ''}`} onClick={onClick}><td className={`whitespace-nowrap px-5 py-3 ${indent ? 'pl-10' : ''} ${bold ? 'font-semibold' : 'text-muted-foreground'}`}>{onClick && (open ? <ChevronDown className="mr-1 inline size-3.5" /> : <ChevronRight className="mr-1 inline size-3.5" />)}{label}</td>{values.map((value, index) => <td key={index} className={`px-3 py-3 text-right ${bold ? 'font-semibold' : 'text-muted-foreground'} ${negative && value < 0 ? 'text-rose-600' : ''}`}>{signedMoney(value)}</td>)}<td className={`px-4 py-3 text-right font-semibold ${negative && total < 0 ? 'text-rose-600' : ''}`}>{signedMoney(total)}</td><td className="px-4 py-3 text-right"><Variance value={variance} /></td></tr> }
