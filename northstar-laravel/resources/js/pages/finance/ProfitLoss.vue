<template>
  <div class="flex flex-col gap-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div>
        <p class="eyebrow">Finance</p>
        <h1 class="page-title">Profit &amp; Loss</h1>
        <p v-if="data" class="mt-1 text-sm text-muted-foreground">
          {{ data.filter ?? 'All stores' }} · {{ date(data.period.from) }} – {{ date(data.period.to) }} · Compared with {{ date(data.period.previous_from) }} – {{ date(data.period.previous_to) }}
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <ChannelFilter v-model="channel" />
        <PeriodSelect v-model="period" />

        <button class="btn-secondary" @click="download('/finance/profit-loss/export', params(), 'northstar-profit-loss.csv')"><FileSpreadsheet class="size-4" />Export CSV</button>
      </div>
    </div>

    <LoadingState v-if="!data" />

    <template v-else>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Metric label="Gross revenue" :line="data.summary.revenue" />
        <Metric label="Gross profit" :line="data.summary.gross_profit" />
        <Metric label="Operating expenses" :line="data.summary.opex" negative />
        <Metric label="Net profit" :line="data.summary.net_profit" highlight />
      </div>

      <div class="grid gap-6 xl:grid-cols-[1.55fr_1fr]">
        <section class="card overflow-hidden" :class="{ 'opacity-60': loading }">
          <div class="flex flex-col gap-3 border-b border-border p-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 class="font-semibold">P&amp;L statement</h2>
              <p class="mt-1 text-xs text-muted-foreground">Accrual basis · {{ workspace.meta?.currency ?? 'USD' }}</p>
            </div>
            <div class="flex rounded-lg bg-muted p-1">
              <button
                v-for="[value, label] in [['channel', 'By channel'], ['month', 'By month'], ['sku', 'By SKU']]"
                :key="value"
                class="rounded-md px-3 py-1.5 text-xs font-medium transition"
                :class="group === value ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground'"
                @click="group = value"
              >{{ label }}</button>
            </div>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm" :style="{ minWidth: `${360 + data.columns.length * 110}px` }">
              <thead class="bg-muted/40 text-left text-xs text-muted-foreground">
                <tr>
                  <th class="px-5 py-3 font-medium">Line item</th>
                  <th v-for="c in data.columns" :key="c.key" class="whitespace-nowrap px-3 py-3 text-right font-medium">{{ c.label }}</th>
                  <th class="px-4 py-3 text-right font-semibold text-foreground">Total</th>
                  <th class="px-4 py-3 text-right font-medium">vs prev.</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="line in data.lines" :key="line.key">
                  <StatementRow :line="line" :bold="true" :highlight="line.type === 'subtotal'" :open="line.children ? expanded.includes(line.key) : null" @toggle="toggle(line.key)" />
                  <template v-if="line.children && expanded.includes(line.key)">
                    <StatementRow v-for="child in line.children" :key="child.key" :line="child" indent />
                  </template>
                </template>
              </tbody>
            </table>
          </div>
        </section>

        <section class="card p-5">
          <h2 class="font-semibold">Profit waterfall</h2>
          <p class="mt-1 text-xs text-muted-foreground">How revenue becomes net profit</p>
          <div class="mt-6 flex h-48 items-end gap-2 border-b border-l border-border px-3 pt-2">
            <div v-for="bar in data.waterfall" :key="bar.label" class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-2" :title="`${bar.label}: ${moneyExact(bar.value)}`">
              <div class="w-full rounded-t-md" :class="barColor(bar)" :style="{ height: `${barHeight(bar.value)}%` }" />
              <span class="w-full truncate text-center text-[10px] text-muted-foreground">{{ bar.label }}</span>
            </div>
          </div>
          <!-- Exact amounts, to the cent, read as a list: they don't fit above narrow bars. -->
          <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
            <div v-for="bar in data.waterfall" :key="bar.label" class="flex items-center justify-between gap-2" :class="{ 'col-span-2 border-t border-border pt-1.5 font-semibold': bar.kind === 'result' }">
              <dt class="flex min-w-0 items-center gap-1.5 text-muted-foreground"><span class="size-2 shrink-0 rounded-sm" :class="barColor(bar)" /><span class="truncate">{{ bar.label }}</span></dt>
              <dd class="tabular-nums" :class="bar.value < 0 ? 'text-rose-600' : ''">{{ moneyExact(bar.value) }}</dd>
            </div>
          </dl>
          <div class="mt-5 flex items-center justify-between rounded-lg bg-muted/50 px-3 py-3">
            <div>
              <p class="text-xs text-muted-foreground">Net margin</p>
              <p class="mt-0.5 text-lg font-semibold">{{ data.summary.net_margin.toFixed(1) }}%</p>
            </div>
            <div class="text-right">
              <p class="text-xs text-muted-foreground">Previous period</p>
              <p class="mt-0.5 text-sm font-semibold" :class="marginDelta === 0 ? 'text-muted-foreground' : marginDelta > 0 ? 'text-emerald-600' : 'text-rose-600'">{{ marginDelta > 0 ? '+' : '' }}{{ marginDelta.toFixed(1) }} pts</p>
            </div>
          </div>
        </section>
      </div>
      <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <Info class="size-3.5" /> Values are calculated from synced order, fee, shipping, product cost and expense data. Shared costs are allocated by revenue share.
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, h, onMounted, ref, watch } from 'vue';
import { ChevronDown, ChevronRight, FileSpreadsheet, Info, TrendingDown, TrendingUp } from 'lucide-vue-next';
import LoadingState from '@/components/LoadingState.vue';
import ChannelFilter, { channelParams } from '@/components/ChannelFilter.vue';
import PeriodSelect from '@/components/PeriodSelect.vue';
import api, { download } from '@/lib/api';
import { date, money, moneyExact, percent, signedMoney } from '@/lib/format';
import { useWorkspaceStore } from '@/stores/workspace';

const workspace = useWorkspaceStore();
const period = ref('this_month');
const group = ref('channel');
const channel = ref('');
const params = () => ({ period: period.value, group: group.value, ...channelParams(channel.value) });
const data = ref(null);
const loading = ref(false);
const expanded = ref(['revenue', 'cogs', 'opex']);

const toggle = (key) => (expanded.value = expanded.value.includes(key) ? expanded.value.filter((k) => k !== key) : [...expanded.value, key]);
const marginDelta = computed(() => data.value.summary.net_margin - data.value.summary.previous_net_margin);
const maxBar = computed(() => Math.max(1, ...data.value.waterfall.map((b) => Math.abs(b.value))));
// Non-zero amounts stay visible as a sliver; zero draws no bar.
const barHeight = (value) => (value === 0 ? 0 : Math.max(2, (Math.abs(value) / maxBar.value) * 100));
const barColor = (bar) =>
  bar.kind === 'total' ? 'bg-indigo-500' : bar.kind === 'result' ? (bar.value >= 0 ? 'bg-emerald-500' : 'bg-rose-500') : bar.label === 'COGS' ? 'bg-rose-400' : 'bg-amber-300';

async function load() {
  loading.value = true;
  try {
    data.value = (await api.get('/finance/profit-loss', { params: params() })).data;
  } finally {
    loading.value = false;
  }
}

watch([period, group, channel], load);
onMounted(load);

function Variance({ line }) {
  if (line.variance === null) return h('span', { class: 'text-xs text-muted-foreground' }, '—');
  const good = line.favourable;
  return h('span', { class: `inline-flex items-center gap-1 text-xs font-semibold ${good ? 'text-emerald-600' : 'text-rose-600'}` }, [
    h(line.variance >= 0 ? TrendingUp : TrendingDown, { class: 'size-3' }),
    percent(line.variance),
  ]);
}

/** This period's size against the larger of this and the previous period; empty when both are zero. */
function metricWidth(line) {
  const scale = Math.max(Math.abs(line.total), Math.abs(line.previous));
  return scale > 0 ? Math.round((Math.abs(line.total) / scale) * 100) : 0;
}

function Metric({ label, line, negative, highlight }) {
  const good = line.favourable ?? true;
  const color = negative || line.total < 0 ? 'bg-rose-400' : good ? 'bg-emerald-500' : 'bg-amber-400';
  return h('section', { class: `rounded-xl border p-5 ${highlight ? 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-900 dark:bg-emerald-950/20' : 'border-border bg-card'}` }, [
    h('p', { class: 'text-sm text-muted-foreground' }, label),
    h('div', { class: 'mt-3 flex items-end justify-between gap-2' }, [
      h('p', { class: 'text-2xl font-semibold tracking-tight' }, money(Math.abs(line.total))),
      h(Variance, { line }),
    ]),
    h('div', { class: 'mt-3 h-1.5 overflow-hidden rounded-full bg-muted', title: `Previous period: ${signedMoney(line.previous)}` }, [
      h('div', { class: `h-full rounded-full transition-[width] ${color}`, style: { width: `${metricWidth(line)}%` } }),
    ]),
  ]);
}
Metric.props = ['label', 'line', 'negative', 'highlight'];

function StatementRow({ line, bold, highlight, indent, open }, { emit }) {
  const clickable = open !== null && open !== undefined;
  const valueClass = (v) => `px-3 py-3 text-right tabular-nums ${bold ? 'font-semibold' : 'text-muted-foreground'} ${v < 0 ? 'text-rose-600' : ''}`;
  return h('tr', { class: `${highlight ? 'border-y border-border bg-muted/35' : 'border-b border-border/70'} ${clickable ? 'cursor-pointer hover:bg-muted/30' : ''}`, onClick: clickable ? () => emit('toggle') : undefined }, [
    h('td', { class: `whitespace-nowrap px-5 py-3 ${indent ? 'pl-10' : ''} ${bold ? 'font-semibold' : 'text-muted-foreground'}` }, [
      clickable ? h(open ? ChevronDown : ChevronRight, { class: 'mr-1 inline size-3.5' }) : null,
      line.label,
    ]),
    ...line.values.map((v) => h('td', { class: valueClass(v) }, signedMoney(v))),
    h('td', { class: `px-4 py-3 text-right font-semibold tabular-nums ${line.total < 0 ? 'text-rose-600' : ''}` }, signedMoney(line.total)),
    h('td', { class: 'px-4 py-3 text-right' }, [h(Variance, { line })]),
  ]);
}
StatementRow.props = ['line', 'bold', 'highlight', 'indent', 'open'];
StatementRow.emits = ['toggle'];
</script>
