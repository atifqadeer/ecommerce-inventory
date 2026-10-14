<template>
  <div class="flex flex-col gap-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div>
        <p class="eyebrow">Finance</p>
        <h1 class="page-title">Balance Sheet</h1>
        <p class="mt-1 text-sm text-muted-foreground">Financial position as of {{ formattedDate }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <label class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm">
          <CalendarDays class="size-4 text-muted-foreground" /><span class="text-muted-foreground">As of</span>
          <input v-model="asOf" aria-label="As of date" type="date" class="bg-transparent font-medium outline-none" />
        </label>
        <button class="btn-primary" @click="openJournal"><Plus class="size-4" />Journal entry</button>
      </div>
    </div>

    <LoadingState v-if="!sheet" />

    <template v-else>
      <section
        class="flex flex-wrap items-center gap-3 rounded-xl border px-4 py-3"
        :class="sheet.balanced ? 'border-emerald-200 bg-emerald-50/70 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-300' : 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/20 dark:text-rose-300'"
      >
        <CircleCheck v-if="sheet.balanced" class="size-5" /><TriangleAlert v-else class="size-5" />
        <div>
          <p class="text-sm font-semibold">{{ sheet.balanced ? 'Balance sheet is balanced' : 'Balance sheet needs review' }}</p>
          <p class="text-xs opacity-80">Assets {{ money(sheet.totals.assets) }} = Liabilities &amp; Equity {{ money(sheet.totals.liabilities + sheet.totals.equity) }}</p>
        </div>
        <span class="ml-auto text-sm font-semibold">{{ sheet.balanced ? '✓ $0 difference' : `${money(sheet.difference)} difference` }}</span>
      </section>

      <div class="grid gap-6 xl:grid-cols-2">
        <BalanceColumn title="Assets" subtitle="What the business owns" :rows="sheet.assets" :total="sheet.totals.assets" :expanded="expanded" @toggle="toggle" @select="select" />
        <BalanceColumn title="Liabilities & Equity" subtitle="What the business owes and owner value" :rows="sheet.liabilities_equity" :total="sheet.totals.liabilities + sheet.totals.equity" :expanded="expanded" @toggle="toggle" @select="select" />
      </div>

      <div class="flex items-center gap-2 text-xs text-muted-foreground"><BookOpen class="size-3.5" /> Click any line item to inspect the journal entries behind the balance.</div>
    </template>

    <Drawer :open="!!selected" :label="`${selected?.name} journal entries`" width="max-w-md" @close="selected = null">
      <template v-if="selected">
        <div class="flex items-start justify-between border-b border-border p-5">
          <div>
            <p class="text-xs font-medium text-indigo-600 dark:text-indigo-400">Journal drill-down</p>
            <h2 class="mt-1 text-lg font-semibold">{{ selected.name }}</h2>
            <p class="mt-1 text-sm text-muted-foreground">{{ selected.detail }}</p>
          </div>
          <button aria-label="Close journal drawer" class="rounded-md p-1.5 text-muted-foreground hover:bg-muted" @click="selected = null"><X class="size-5" /></button>
        </div>
        <div class="border-b border-border bg-muted/30 p-5">
          <p class="text-xs text-muted-foreground">Balance at {{ formattedDate }}</p>
          <p class="mt-1 text-2xl font-semibold">{{ money(selected.balance) }}</p>
        </div>
        <div class="flex-1 p-5">
          <h3 class="text-sm font-semibold">Journal entries</h3>
          <LoadingState v-if="!entries" />
          <div v-else class="mt-5 flex flex-col gap-5">
            <div v-for="entry in entries" :key="entry.id" class="relative border-l-2 border-indigo-200 pl-5 dark:border-indigo-900">
              <span class="absolute -left-[7px] top-0 size-3 rounded-full border-2 border-card bg-indigo-500" />
              <p class="text-xs font-medium text-indigo-600 dark:text-indigo-400">{{ date(entry.date) }} · {{ entry.reference }}</p>
              <p class="mt-1 text-sm font-medium">{{ entry.description }}</p>
              <div class="mt-2 flex gap-4 text-xs text-muted-foreground">
                <span>Debit <b class="text-foreground">{{ moneyExact(entry.debit) }}</b></span>
                <span>Credit <b class="text-foreground">{{ moneyExact(entry.credit) }}</b></span>
              </div>
            </div>
            <p v-if="!entries.length" class="text-sm text-muted-foreground">No entries up to this date.</p>
          </div>
        </div>
      </template>
    </Drawer>

    <Modal :open="journal.open" title="New journal entry" description="Debits must equal credits." width="max-w-2xl" @close="journal.open = false">
      <form class="space-y-4" @submit.prevent="saveJournal">
        <div class="grid gap-4 sm:grid-cols-[160px_1fr]">
          <label class="label">Date<input v-model="journal.date" type="date" class="input mt-2" /></label>
          <label class="label">Description<input v-model="journal.description" class="input mt-2" /><p v-if="journal.errors.description" class="field-error">{{ journal.errors.description }}</p></label>
        </div>
        <div>
          <div class="grid grid-cols-[1fr_110px_110px_32px] gap-2 text-xs font-medium text-muted-foreground"><span>Account</span><span>Debit</span><span>Credit</span><span /></div>
          <div v-for="(line, i) in journal.lines" :key="i" class="mt-2 grid grid-cols-[1fr_110px_110px_32px] gap-2">
            <select v-model="line.ledger_account_id" aria-label="Account" class="input">
              <option value="" disabled>Select account</option>
              <optgroup v-for="type in ['asset', 'liability', 'equity']" :key="type" :label="capitalize(type)">
                <option v-for="a in accounts.filter((a) => a.type === type)" :key="a.id" :value="a.id">{{ a.parent_id ? '— ' : '' }}{{ a.name }}</option>
              </optgroup>
            </select>
            <input v-model.number="line.debit" type="number" min="0" step="0.01" aria-label="Debit" class="input" @input="line.credit = null" />
            <input v-model.number="line.credit" type="number" min="0" step="0.01" aria-label="Credit" class="input" @input="line.debit = null" />
            <button type="button" aria-label="Remove line" class="rounded-lg text-muted-foreground hover:bg-muted disabled:opacity-30" :disabled="journal.lines.length <= 2" @click="journal.lines.splice(i, 1)"><Trash2 class="mx-auto size-4" /></button>
          </div>
          <button type="button" class="mt-2 text-xs font-medium text-indigo-600 dark:text-indigo-400" @click="journal.lines.push(blankLine())">+ Add line</button>
          <p v-if="journal.errors.lines" class="field-error">{{ journal.errors.lines }}</p>
        </div>
        <div class="flex items-center justify-between border-t border-border pt-4 text-sm">
          <span :class="balancedDraft ? 'text-emerald-600' : 'text-rose-600'">Debits {{ moneyExact(draftDebits) }} · Credits {{ moneyExact(draftCredits) }}</span>
          <div class="flex gap-2">
            <button type="button" class="btn-secondary" @click="journal.open = false">Cancel</button>
            <button type="submit" class="btn-primary" :disabled="journal.busy || !balancedDraft">Post entry</button>
          </div>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref, watch } from 'vue';
import { ArrowUpRight, BookOpen, CalendarDays, ChevronRight, CircleCheck, Plus, Trash2, TriangleAlert, X } from 'lucide-vue-next';
import Drawer from '@/components/Drawer.vue';
import LoadingState from '@/components/LoadingState.vue';
import Modal from '@/components/Modal.vue';
import api, { errorMessage, fieldErrors } from '@/lib/api';
import { capitalize, date, money, moneyExact } from '@/lib/format';
import { toast } from '@/lib/toast';

const todayIso = () => new Date().toLocaleDateString('en-CA');

const asOf = ref(todayIso());
const sheet = ref(null);
const expanded = ref([]);
const selected = ref(null);
const entries = ref(null);
const accounts = ref([]);

const blankLine = () => ({ ledger_account_id: '', debit: null, credit: null });
const journal = reactive({ open: false, busy: false, date: todayIso(), description: '', lines: [], errors: {} });

const formattedDate = computed(() => new Date(`${asOf.value}T12:00:00`).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }));
const draftDebits = computed(() => journal.lines.reduce((s, l) => s + (Number(l.debit) || 0), 0));
const draftCredits = computed(() => journal.lines.reduce((s, l) => s + (Number(l.credit) || 0), 0));
const balancedDraft = computed(() => draftDebits.value > 0 && Math.abs(draftDebits.value - draftCredits.value) < 0.005);

const toggle = (id) => (expanded.value = expanded.value.includes(id) ? expanded.value.filter((x) => x !== id) : [...expanded.value, id]);

async function load() {
  if (!asOf.value) return;
  sheet.value = (await api.get('/finance/balance-sheet', { params: { as_of: asOf.value } })).data;
}

async function select(row) {
  selected.value = row;
  entries.value = null;
  entries.value = (await api.get(`/finance/accounts/${row.id}/entries`, { params: { as_of: asOf.value } })).data.entries;
}

async function openJournal() {
  Object.assign(journal, { open: true, date: todayIso(), description: '', lines: [blankLine(), blankLine()], errors: {} });
  if (!accounts.value.length) accounts.value = (await api.get('/finance/accounts')).data;
}

async function saveJournal() {
  journal.busy = true;
  journal.errors = {};
  try {
    const lines = journal.lines.filter((l) => l.ledger_account_id && (l.debit || l.credit)).map((l) => ({ ledger_account_id: l.ledger_account_id, debit: l.debit || 0, credit: l.credit || 0 }));
    const { data } = await api.post('/finance/journal-entries', { date: journal.date, description: journal.description, lines });
    toast(`${data.reference} posted`);
    journal.open = false;
    await load();
  } catch (error) {
    journal.errors = fieldErrors(error);
    if (!Object.keys(journal.errors).length) toast(errorMessage(error), 'error');
  } finally {
    journal.busy = false;
  }
}

watch(asOf, load);
onMounted(load);

function BalanceColumn({ title, subtitle, rows, total, expanded }, { emit }) {
  const row = (r) => [
    h('button', { class: 'flex w-full items-center gap-3 px-5 py-4 text-left hover:bg-muted/40', onClick: () => emit('select', r) }, [
      h('span', { class: 'flex size-8 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-300' }, [h(ArrowUpRight, { class: 'size-4' })]),
      h('span', { class: 'min-w-0 flex-1' }, [
        h('span', { class: 'block text-sm font-medium' }, r.name),
        h('span', { class: 'mt-0.5 block text-xs text-muted-foreground' }, r.detail),
      ]),
      r.children.length
        ? h('span', { role: 'button', 'aria-label': `Expand ${r.name}`, class: 'rounded p-1 text-muted-foreground hover:bg-muted', onClick: (e) => { e.stopPropagation(); emit('toggle', r.id); } }, [
            h(ChevronRight, { class: `size-4 transition-transform ${expanded.includes(r.id) ? 'rotate-90' : ''}` }),
          ])
        : null,
      h('span', { class: 'text-sm font-semibold tabular-nums' }, money(r.balance)),
    ]),
    r.children.length && expanded.includes(r.id)
      ? h('div', { class: 'bg-muted/20 px-5 pb-3 pl-16' }, r.children.map((c) =>
          h('button', { class: 'flex w-full items-center justify-between border-t border-border/60 py-2.5 text-left text-sm', onClick: () => emit('select', { ...c, children: [] }) }, [
            h('span', { class: 'text-muted-foreground' }, c.name),
            h('span', { class: 'font-medium tabular-nums' }, money(c.balance)),
          ])))
      : null,
  ];

  return h('section', { class: 'card overflow-hidden' }, [
    h('div', { class: 'border-b border-border p-5' }, [h('h2', { class: 'font-semibold' }, title), h('p', { class: 'mt-1 text-xs text-muted-foreground' }, subtitle)]),
    h('div', { class: 'divide-y divide-border' }, [
      ...rows.map((r) => h('div', { key: r.id }, row(r))),
      h('div', { class: 'flex items-center justify-between bg-muted/40 px-5 py-4' }, [
        h('span', { class: 'text-sm font-semibold' }, `Total ${title}`),
        h('span', { class: 'text-lg font-semibold tabular-nums' }, money(total)),
      ]),
    ]),
  ]);
}
BalanceColumn.props = ['title', 'subtitle', 'rows', 'total', 'expanded'];
BalanceColumn.emits = ['toggle', 'select'];
</script>
