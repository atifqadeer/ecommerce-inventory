<template>
  <div class="flex flex-col gap-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div>
        <p class="eyebrow">Finance</p>
        <h1 class="page-title">Cash flow{{ data?.store ? ` · ${data.store.name}` : '' }}</h1>
        <p v-if="data" class="mt-1 text-sm text-muted-foreground">
          {{ date(data.period.from) }} – {{ date(data.period.to) }} ·
          {{ data.store ? `${data.store.platform} store${data.store.handle ? ` (${data.store.handle})` : ''}` : 'All stores' }} · Money actually received and paid out
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <label class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm">
          <Store class="size-4 text-muted-foreground" />
          <select v-model="store" aria-label="Store" class="bg-card font-medium text-card-foreground outline-none">
            <option value="">All stores</option>
            <optgroup v-for="g in storeGroups" :key="g.platform" :label="g.platform">
              <option v-for="c in g.stores" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </optgroup>
          </select>
        </label>
        <PeriodSelect v-model="period" />

        <button class="btn-secondary" @click="download('/finance/cash-flow/export', params(), 'northstar-cash-flow.csv')"><FileSpreadsheet class="size-4" />Export CSV</button>
      </div>
    </div>

    <LoadingState v-if="!data" />

    <template v-else>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <section v-for="card in cards" :key="card.label" class="rounded-xl border p-5" :class="card.highlight ? 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-900 dark:bg-emerald-950/20' : 'border-border bg-card'">
          <p class="text-sm text-muted-foreground">{{ card.label }}</p>
          <p class="mt-3 text-2xl font-semibold tracking-tight" :class="card.value < 0 ? 'text-rose-600' : ''">{{ signedMoney(card.value) }}</p>
          <p class="mt-2 text-xs text-muted-foreground">{{ card.note }}</p>
        </section>
      </div>

      <section class="card overflow-hidden">
        <div class="border-b border-border p-5">
          <h2 class="font-semibold">Cash in hand</h2>
          <p class="mt-1 text-xs text-muted-foreground">Where your cash came from and went. Buying lowers cash in hand when you pay the supplier, not when the stock arrives.</p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm" :style="{ minWidth: `${360 + data.columns.length * 110}px` }">
            <thead class="bg-muted/40 text-left text-xs text-muted-foreground">
              <tr>
                <th class="px-5 py-3 font-medium" />
                <th v-for="c in data.columns" :key="c.key" class="whitespace-nowrap px-3 py-3 text-right font-medium">{{ c.label }}</th>
                <th class="px-4 py-3 text-right font-semibold text-foreground">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr class="border-b border-border/70">
                <td class="px-5 py-3 font-semibold">Cash in hand at start</td>
                <td :colspan="data.columns.length" />
                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ signedMoney(data.cash_in_hand.opening) }}</td>
              </tr>
              <Row v-for="r in data.cash_in_hand.rows" :key="r.key" :line="{ ...r, children: [] }" />
              <tr v-if="!data.cash_in_hand.rows.length"><td :colspan="data.columns.length + 2" class="px-5 py-3 pl-8 text-xs text-muted-foreground">No cash movements in this period</td></tr>
              <tr class="border-y border-border bg-muted/35">
                <td class="px-5 py-3 font-semibold">Cash in hand at end</td>
                <td :colspan="data.columns.length" />
                <td class="px-4 py-3 text-right font-semibold tabular-nums" :class="data.cash_in_hand.closing < 0 ? 'text-rose-600' : ''">{{ signedMoney(data.cash_in_hand.closing) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <dl v-if="data.cash_in_hand.owed_to_suppliers || data.cash_in_hand.owed_by_marketplaces" class="flex flex-wrap gap-x-8 gap-y-2 border-t border-border p-5 text-sm">
          <div v-if="data.cash_in_hand.owed_to_suppliers">
            <dt class="text-xs text-muted-foreground">Still to pay suppliers (bought, not yet paid)</dt>
            <dd class="mt-0.5 font-semibold tabular-nums text-rose-600">−{{ moneyExact(data.cash_in_hand.owed_to_suppliers) }}</dd>
          </div>
          <div v-if="data.cash_in_hand.owed_by_marketplaces">
            <dt class="text-xs text-muted-foreground">Still to receive from marketplaces (sold, not yet paid out)</dt>
            <dd class="mt-0.5 font-semibold tabular-nums text-emerald-600">+{{ moneyExact(data.cash_in_hand.owed_by_marketplaces) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-muted-foreground">Cash in hand once both are settled</dt>
            <dd class="mt-0.5 font-semibold tabular-nums">{{ signedMoney(data.cash_in_hand.closing - data.cash_in_hand.owed_to_suppliers + (data.cash_in_hand.owed_by_marketplaces ?? 0)) }}</dd>
          </div>
        </dl>
      </section>
      <section class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="font-semibold">Cash in and out by month</h2>
            <p class="mt-1 text-xs text-muted-foreground">Closing cash is shown under each month.</p>
          </div>
          <div class="flex items-center gap-4 text-xs text-muted-foreground">
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-emerald-500" />Cash in</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-rose-400" />Cash out</span>
          </div>
        </div>
        <div class="mt-5 flex gap-2" role="img" :aria-label="`Monthly cash in and out, ${data.period.label}`">
          <div
            v-for="m in data.months"
            :key="m.key"
            class="group relative flex min-w-0 flex-1 flex-col items-center rounded-md px-1 pt-1 hover:bg-muted/50"
            tabindex="0"
          >
            <div class="flex h-28 w-full items-end justify-center">
              <div class="w-full max-w-10 rounded-t bg-emerald-500" :style="{ height: barHeight(m.inflow) }" />
            </div>
            <div class="h-px w-full bg-border" />
            <div class="flex h-28 w-full items-start justify-center pt-[2px]">
              <div class="w-full max-w-10 rounded-b bg-rose-400" :style="{ height: barHeight(m.outflow) }" />
            </div>
            <span class="mt-2 w-full truncate text-center text-[11px] text-muted-foreground">{{ m.label }}</span>
            <span class="w-full truncate text-center text-[11px] font-semibold tabular-nums" :class="m.closing < 0 ? 'text-rose-600' : ''" :title="money(m.closing)">{{ money(m.closing) }}</span>
            <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden w-44 -translate-x-1/2 rounded-lg border border-border bg-card p-3 text-xs shadow-lg group-hover:block group-focus:block">
              <p class="font-semibold">{{ m.label }}</p>
              <dl class="mt-1.5 space-y-1">
                <div class="flex justify-between gap-2"><dt class="flex items-center gap-1.5 text-muted-foreground"><span class="size-2 rounded-sm bg-emerald-500" />In</dt><dd class="tabular-nums">{{ moneyExact(m.inflow) }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="flex items-center gap-1.5 text-muted-foreground"><span class="size-2 rounded-sm bg-rose-400" />Out</dt><dd class="tabular-nums">{{ moneyExact(m.outflow) }}</dd></div>
                <div class="flex justify-between gap-2 border-t border-border pt-1"><dt class="text-muted-foreground">Net</dt><dd class="font-semibold tabular-nums">{{ signedMoney(m.net) }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-muted-foreground">Closing cash</dt><dd class="font-semibold tabular-nums">{{ signedMoney(m.closing) }}</dd></div>
                <div v-if="data.receivables.tracked" class="flex justify-between gap-2"><dt class="text-muted-foreground">Owed by marketplace</dt><dd class="tabular-nums">{{ signedMoney(m.receivable) }}</dd></div>
              </dl>
            </div>
          </div>
        </div>
      </section>

      <section class="card overflow-hidden" :class="{ 'opacity-60': loading }">
        <div class="flex flex-col gap-3 border-b border-border p-5 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="font-semibold">Cash flow statement</h2>
            <p class="mt-1 text-xs text-muted-foreground">
              {{ data.method === 'indirect' ? 'Indirect method · starts from net profit and adjusts to cash' : 'Direct method · cash received and paid' }}
              · {{ workspace.meta?.currency ?? 'USD' }}
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
          <div class="flex rounded-lg bg-muted p-1" role="group" aria-label="Method">
            <button
              v-for="[value, label] in [['indirect', 'Indirect (from profit)'], ['direct', 'Direct']]"
              :key="value"
              class="rounded-md px-3 py-1.5 text-xs font-medium transition"
              :class="method === value ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground'"
              @click="method = value"
            >{{ label }}</button>
          </div>
          <div v-if="!data.store" class="flex rounded-lg bg-muted p-1">
            <button
              v-for="[value, label] in [['month', 'By month'], ['channel', 'By store']]"
              :key="value"
              class="rounded-md px-3 py-1.5 text-xs font-medium transition"
              :class="group === value ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground'"
              @click="group = value"
            >{{ label }}</button>
          </div>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm" :style="{ minWidth: `${360 + data.columns.length * 110}px` }">
            <thead class="bg-muted/40 text-left text-xs text-muted-foreground">
              <tr>
                <th class="px-5 py-3 font-medium">Line item</th>
                <th v-for="c in data.columns" :key="c.key" class="whitespace-nowrap px-3 py-3 text-right font-medium">{{ c.label }}</th>
                <th class="px-4 py-3 text-right font-semibold text-foreground">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr class="border-b border-border/70">
                <td class="px-5 py-3 font-semibold">Opening cash</td>
                <td :colspan="data.columns.length" />
                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ signedMoney(data.summary.opening) }}</td>
              </tr>
              <template v-for="section in data.sections" :key="section.key">
                <tr class="bg-muted/20"><td :colspan="data.columns.length + 2" class="px-5 pb-1 pt-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ section.label }}</td></tr>
                <template v-for="line in section.lines" :key="line.key">
                  <Row :line="line" :open="line.children.length ? expanded.includes(line.key) : null" @toggle="toggle(line.key)" />
                  <template v-if="expanded.includes(line.key)">
                    <Row v-for="child in line.children" :key="child.key" :line="child" indent />
                  </template>
                </template>
                <tr v-if="!section.lines.length"><td :colspan="data.columns.length + 2" class="px-5 py-2 pl-10 text-xs text-muted-foreground">No cash movements</td></tr>
                <Row :line="{ ...section, label: `Net cash from ${section.label.toLowerCase()}` }" bold />
              </template>
              <Row :line="data.net_change" bold highlight />
              <tr class="border-t border-border">
                <td class="px-5 py-3 font-semibold">Closing cash</td>
                <td :colspan="data.columns.length" />
                <td class="px-4 py-3 text-right font-semibold tabular-nums" :class="data.summary.closing < 0 ? 'text-rose-600' : ''">{{ signedMoney(data.summary.closing) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card overflow-hidden">
        <div class="border-b border-border p-5">
          <h2 class="font-semibold">Receivables · owed by marketplaces</h2>
          <p class="mt-1 text-xs text-muted-foreground">Sales already earned (after fees, refunds and tax) that haven't reached your bank yet.</p>
        </div>
        <p v-if="!data.receivables.tracked" class="p-5 text-sm text-muted-foreground">
          Not tracked yet. Import the store's payment transactions and payouts (Shopify: Finance → Payouts → Export) to see what the marketplace owes you.
        </p>
        <template v-else>
          <div class="overflow-x-auto">
            <table class="w-full text-sm" :style="{ minWidth: `${360 + data.columns.length * 110}px` }">
              <thead class="bg-muted/40 text-left text-xs text-muted-foreground">
                <tr>
                  <th class="px-5 py-3 font-medium">Line item</th>
                  <th v-for="c in data.columns" :key="c.key" class="whitespace-nowrap px-3 py-3 text-right font-medium">{{ c.label }}</th>
                  <th class="px-4 py-3 text-right font-semibold text-foreground">Total</th>
                </tr>
              </thead>
              <tbody>
                <tr class="border-b border-border/70">
                  <td class="px-5 py-3 font-semibold">Owed at start of period</td>
                  <td :colspan="data.columns.length" />
                  <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ signedMoney(data.receivables.opening) }}</td>
                </tr>
                <Row :line="{ label: 'Sales earned', ...data.receivables.earned }" />
                <Row :line="{ label: 'Paid out to bank', ...data.receivables.collected }" />
                <tr v-if="data.group === 'month'" class="border-b border-border/70">
                  <td class="px-5 py-3 pl-8 text-muted-foreground">Owed at month end</td>
                  <td v-for="m in data.months" :key="m.key" class="px-3 py-3 text-right tabular-nums text-muted-foreground">{{ signedMoney(m.receivable) }}</td>
                  <td />
                </tr>
                <tr class="border-y border-border bg-muted/35">
                  <td class="px-5 py-3 font-semibold">Owed at end of period</td>
                  <td :colspan="data.columns.length" />
                  <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ signedMoney(data.receivables.closing) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="border-t border-border p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <h3 class="text-sm font-semibold">Expected payouts</h3>
              <p class="text-xs text-muted-foreground">As of your latest import ({{ date(`${data.receivables.as_of}T12:00:00`) }}) · {{ moneyExact(data.receivables.current) }} owed</p>
            </div>
            <p v-if="data.receivables.overdue" class="mt-2 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
              <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
              {{ moneyExact(data.receivables.overdue) }} was due to be paid out before today. These payouts have most likely arrived: import newer payouts and transactions files to update their status.
            </p>
            <table v-if="data.receivables.outstanding.length" class="mt-3 w-full text-sm">
              <thead class="text-left text-xs text-muted-foreground">
                <tr>
                  <th class="py-2 font-medium">Expected</th>
                  <th class="py-2 font-medium">Status</th>
                  <th v-if="!data.store" class="py-2 font-medium">Store</th>
                  <th class="py-2 text-right font-medium">Transactions</th>
                  <th class="py-2 text-right font-medium">Amount</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="o in data.receivables.outstanding" :key="`${o.store}-${o.date}-${o.status}`">
                  <td class="py-2">{{ o.date ? date(`${o.date}T12:00:00`) : 'Not scheduled' }}<span v-if="o.date && o.date < today" class="ml-2 text-xs text-amber-600 dark:text-amber-400">date passed</span></td>
                  <td class="py-2"><span class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">{{ statusLabel(o.status) }}</span></td>
                  <td v-if="!data.store" class="py-2 text-muted-foreground">{{ o.store }}</td>
                  <td class="py-2 text-right tabular-nums text-muted-foreground">{{ o.count }}</td>
                  <td class="py-2 text-right font-medium tabular-nums" :class="o.amount < 0 ? 'text-rose-600' : ''">{{ signedMoney(o.amount) }}</td>
                </tr>
              </tbody>
            </table>
            <p v-else class="mt-3 text-sm text-muted-foreground">Nothing outstanding: every sale has been paid out.</p>
          </div>
        </template>
      </section>
      <ul class="space-y-1.5 text-xs text-muted-foreground">
        <li v-for="note in [...data.notes, ...data.receivables.notes]" :key="note" class="flex gap-2 text-amber-700 dark:text-amber-300"><TriangleAlert class="mt-0.5 size-3.5 shrink-0" />{{ note }}</li>
        <li class="flex gap-2"><Info class="mt-0.5 size-3.5 shrink-0" />Payouts count when they reach the bank; fees and charges a marketplace deducts before paying out are inside the payout, so they aren't counted again as expenses. Stock received is counted at unit cost.</li>
      </ul>
    </template>
  </div>
</template>

<script setup>
import { computed, h, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronDown, ChevronRight, FileSpreadsheet, Info, Store, TriangleAlert } from 'lucide-vue-next';
import LoadingState from '@/components/LoadingState.vue';
import PeriodSelect from '@/components/PeriodSelect.vue';
import api, { download } from '@/lib/api';
import { date, money, moneyExact, signedMoney } from '@/lib/format';
import { useWorkspaceStore } from '@/stores/workspace';

const workspace = useWorkspaceStore();
const route = useRoute();
const router = useRouter();
// The selected store lives in the URL (?store=12), so a store's statement can be bookmarked.
const store = ref(route.query.store ? String(route.query.store) : '');
const period = ref('last_6_months');
const group = ref('month');
const method = ref(route.query.method === 'direct' ? 'direct' : 'indirect');
const data = ref(null);
const loading = ref(false);
const expanded = ref([]);

const storeGroups = computed(() => {
  const platforms = (workspace.meta?.supported_channels ?? []).map((s) => s.name);
  return platforms
    .map((platform) => ({ platform, stores: workspace.channels.filter((c) => c.platform === platform) }))
    .filter((g) => g.stores.length);
});
const params = () => ({ period: period.value, group: group.value, method: method.value, channel_id: store.value || undefined });

const today = new Date().toLocaleDateString('en-CA');
const statusLabel = (s) => s.replace('_', ' ').replace(/^./, (c) => c.toUpperCase());

const toggle = (key) => (expanded.value = expanded.value.includes(key) ? expanded.value.filter((k) => k !== key) : [...expanded.value, key]);

const cards = computed(() => {
  const s = data.value.summary;
  const r = data.value.receivables;
  return [
    { label: 'Opening cash', value: s.opening, note: `On ${date(data.value.period.from)}` },
    { label: 'Cash in', value: s.inflow, note: 'Payouts, settlements and other receipts' },
    { label: 'Cash out', value: s.outflow, note: 'Expenses, stock and other payments' },
    { label: 'Closing cash', value: s.closing, note: `Net change ${signedMoney(s.net)}`, highlight: true },
    { label: 'Receivables', value: r.tracked ? r.closing : 0, note: r.tracked ? `Owed by marketplaces at ${date(data.value.period.to)}` : 'Import payouts and transactions to track' },
  ];
});

// Both directions share one scale, so a month's in and out bars are directly comparable.
const scale = computed(() => Math.max(1, ...data.value.months.flatMap((m) => [m.inflow, Math.abs(m.outflow)])));
const barHeight = (value) => (value === 0 ? '0' : `${Math.max(3, (Math.abs(value) / scale.value) * 100)}%`);

async function load() {
  loading.value = true;
  try {
    data.value = (await api.get('/finance/cash-flow', { params: params() })).data;
  } finally {
    loading.value = false;
  }
}

watch([period, group], load);
watch(method, (value) => {
  router.replace({ query: { ...route.query, method: value === 'indirect' ? undefined : value } });
  load();
});
watch(store, (value) => {
  router.replace({ query: { ...route.query, store: value || undefined } });
  load();
});
onMounted(load);

function Row({ line, bold, highlight, indent, open }, { emit }) {
  const clickable = open !== null && open !== undefined;
  const cell = (v) => h('td', { class: `px-3 py-3 text-right tabular-nums ${bold ? 'font-semibold' : 'text-muted-foreground'} ${v < 0 ? 'text-rose-600' : ''}` }, v === 0 ? '—' : signedMoney(v));
  return h('tr', { class: `${highlight ? 'border-y border-border bg-muted/35' : 'border-b border-border/70'} ${clickable ? 'cursor-pointer hover:bg-muted/30' : ''}`, onClick: clickable ? () => emit('toggle') : undefined }, [
    h('td', { class: `whitespace-nowrap px-5 py-3 ${indent ? 'pl-12 text-xs' : bold ? '' : 'pl-8'} ${bold ? 'font-semibold' : 'text-muted-foreground'}` }, [
      clickable ? h(open ? ChevronDown : ChevronRight, { class: 'mr-1 inline size-3.5' }) : null,
      line.label,
    ]),
    ...line.values.map(cell),
    h('td', { class: `px-4 py-3 text-right font-semibold tabular-nums ${line.total < 0 ? 'text-rose-600' : ''}` }, signedMoney(line.total)),
  ]);
}
Row.props = ['line', 'bold', 'highlight', 'indent', 'open'];
Row.emits = ['toggle'];
</script>
