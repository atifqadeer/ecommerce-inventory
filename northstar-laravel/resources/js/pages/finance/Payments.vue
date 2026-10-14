<template>
  <div class="space-y-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
      <div>
        <p class="eyebrow">Finance</p>
        <h1 class="page-title">Payments & payouts</h1>
        <p class="mt-1 text-sm text-muted-foreground">Money moving through your stores' payment accounts. Enter it by hand or import it; matching records are never stored twice.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="btn-secondary" :disabled="!stores.length" @click="upload = true"><Upload class="size-4" />Import file</button>
        <button class="btn-primary" :disabled="!stores.length" @click="tab === 'transactions' ? openTransaction() : openPayout()"><Plus class="size-4" />{{ tab === 'transactions' ? 'Add transaction' : 'Add payout' }}</button>
      </div>
    </div>

    <p v-if="workspace.meta && !stores.length" class="card p-10 text-center text-sm text-muted-foreground">Add a store on the Channels page first.</p>

    <template v-else>
      <div class="card flex flex-wrap items-center gap-2 p-3">
        <div class="flex rounded-lg bg-muted p-1">
          <button
            v-for="[value, label] in [['transactions', 'Transactions'], ['payouts', 'Payouts']]"
            :key="value"
            class="rounded-md px-3 py-1.5 text-xs font-medium transition"
            :class="tab === value ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground'"
            @click="tab = value"
          >{{ label }}</button>
        </div>
        <select v-model="filters.channel_id" aria-label="Store" class="input w-auto">
          <option value="">All stores</option>
          <option v-for="c in stores" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <template v-if="tab === 'transactions'">
          <select v-model="filters.type" aria-label="Type" class="input w-auto">
            <option value="">All types</option>
            <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
          <div class="relative min-w-[180px] flex-1">
            <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <input v-model="search" placeholder="Order number" class="input pl-9" />
          </div>
        </template>
        <select v-else v-model="filters.status" aria-label="Status" class="input w-auto">
          <option value="">All statuses</option>
          <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
        </select>
      </div>

      <div v-if="tab === 'transactions' && summary.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <button
          v-for="s in summary.slice(0, 8)"
          :key="s.type"
          class="card p-4 text-left transition hover:border-indigo-300"
          :class="{ 'border-indigo-400 ring-1 ring-indigo-400': filters.type === s.type }"
          @click="filters.type = filters.type === s.type ? '' : s.type"
        >
          <p class="text-xs text-muted-foreground">{{ s.label }} · {{ number(s.count) }}</p>
          <p class="mt-1 text-lg font-semibold tabular-nums" :class="s.amount < 0 ? 'text-rose-600' : ''">{{ signedMoney(s.amount) }}</p>
          <p v-if="s.fee" class="text-xs text-muted-foreground">Fees {{ moneyExact(s.fee) }}</p>
        </button>
      </div>

      <section class="card" :class="{ 'opacity-60': loading }">
        <div class="overflow-x-auto">
          <table v-if="tab === 'transactions'" class="w-full min-w-[900px] text-left text-sm">
            <thead class="table-head">
              <tr>
                <th class="px-5 py-3">Date</th><th class="px-3 py-3">Store</th><th class="px-3 py-3">Type</th><th class="px-3 py-3">Order</th>
                <th class="px-3 py-3 text-right">Amount</th><th class="px-3 py-3 text-right">Fee</th><th class="px-3 py-3 text-right">Net</th>
                <th class="px-3 py-3">Payout</th><th class="px-3 py-3">Source</th><th />
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="t in rows" :key="t.id">
                <td class="whitespace-nowrap px-5 py-3 text-muted-foreground">{{ dateTime(t.occurred_at) }}</td>
                <td class="px-3 py-3"><ChannelBadge :name="t.channel?.name" :platform="t.channel?.platform" /></td>
                <td class="px-3 py-3 font-medium">{{ t.type_label }}<p v-if="t.note" class="text-xs font-normal text-muted-foreground">{{ t.note }}</p></td>
                <td class="px-3 py-3">
                  <RouterLink v-if="t.order" :to="{ name: 'order', params: { id: t.order.id } }" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ t.order.number }}</RouterLink>
                  <span v-else class="text-muted-foreground">{{ t.order_ref ? `#${t.order_ref}` : '—' }}</span>
                </td>
                <td class="px-3 py-3 text-right tabular-nums" :class="t.amount < 0 ? 'text-rose-600' : ''">{{ signedMoney(t.amount) }}</td>
                <td class="px-3 py-3 text-right tabular-nums text-muted-foreground">{{ t.fee ? moneyExact(t.fee) : '—' }}</td>
                <td class="px-3 py-3 text-right font-semibold tabular-nums" :class="t.net < 0 ? 'text-rose-600' : ''">{{ signedMoney(t.net) }}</td>
                <td class="whitespace-nowrap px-3 py-3 text-xs text-muted-foreground">{{ t.payout_date ? date(`${t.payout_date}T12:00:00`) : '—' }}<template v-if="t.payout_status"> · {{ t.payout_status }}</template></td>
                <td class="px-3 py-3"><SourceBadge :record="t" /></td>
                <td class="px-3 py-3 text-right"><button aria-label="Delete transaction" class="rounded-md p-1.5 text-muted-foreground hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/40" @click="remove(t)"><Trash2 class="size-4" /></button></td>
              </tr>
            </tbody>
          </table>

          <table v-else class="w-full min-w-[900px] text-left text-sm">
            <thead class="table-head">
              <tr>
                <th class="px-5 py-3">Date</th><th class="px-3 py-3">Store</th><th class="px-3 py-3">Status</th>
                <th class="px-3 py-3 text-right">Charges</th><th class="px-3 py-3 text-right">Refunds</th><th class="px-3 py-3 text-right">Adjustments</th>
                <th class="px-3 py-3 text-right">Tax</th><th class="px-3 py-3 text-right">Fees</th><th class="px-3 py-3 text-right">Total</th>
                <th class="px-3 py-3">Source</th><th />
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="p in rows" :key="p.id">
                <td class="whitespace-nowrap px-5 py-3 text-muted-foreground">{{ date(`${p.paid_on}T12:00:00`) }}<p v-if="p.bank_reference" class="text-xs">Ref {{ p.bank_reference }}</p></td>
                <td class="px-3 py-3"><ChannelBadge :name="p.channel?.name" :platform="p.channel?.platform" /></td>
                <td class="px-3 py-3">
                  <select :value="p.status" :aria-label="`Status of payout on ${p.paid_on}`" class="rounded-md border border-border bg-background px-2 py-1 text-xs font-medium" @change="setStatus(p, $event.target.value)">
                    <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
                  </select>
                </td>
                <td class="px-3 py-3 text-right tabular-nums">{{ moneyExact(p.charges) }}</td>
                <td class="px-3 py-3 text-right tabular-nums text-muted-foreground">{{ signedMoney(p.refunds) }}</td>
                <td class="px-3 py-3 text-right tabular-nums text-muted-foreground">{{ signedMoney(p.adjustments) }}</td>
                <td class="px-3 py-3 text-right tabular-nums text-muted-foreground">{{ signedMoney(p.tax) }}</td>
                <td class="px-3 py-3 text-right tabular-nums text-muted-foreground">{{ moneyExact(p.fees) }}</td>
                <td class="px-3 py-3 text-right font-semibold tabular-nums" :class="p.total < 0 ? 'text-rose-600' : ''">{{ signedMoney(p.total) }}</td>
                <td class="px-3 py-3"><SourceBadge :record="p" /></td>
                <td class="px-3 py-3 text-right"><button aria-label="Delete payout" class="rounded-md p-1.5 text-muted-foreground hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/40" @click="remove(p)"><Trash2 class="size-4" /></button></td>
              </tr>
            </tbody>
          </table>
          <p v-if="!loading && !rows.length" class="py-10 text-center text-sm text-muted-foreground">Nothing here yet. Import a file from Shopify (Finance → Payouts → Export) or add entries by hand.</p>
        </div>
        <Pagination :meta="meta" @change="load" />
      </section>
    </template>

    <Modal :open="txForm.open" title="Add transaction" description="If the same transaction is later imported, the import updates this entry instead of adding another." @close="txForm.open = false">
      <form class="space-y-4" @submit.prevent="saveTransaction">
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="label">Store
            <select v-model="txForm.channel_id" class="input mt-2"><option v-for="c in stores" :key="c.id" :value="c.id">{{ c.name }}</option></select>
          </label>
          <label class="label">Type
            <select v-model="txForm.type" class="input mt-2"><option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option></select>
          </label>
          <label class="label">Date<input v-model="txForm.occurred_at" type="date" class="input mt-2" /></label>
          <label class="label">Order number <span class="font-normal text-muted-foreground">(if any)</span><input v-model="txForm.order_ref" placeholder="#2542614" class="input mt-2" /></label>
          <label class="label">Amount
            <input v-model.number="txForm.amount" type="number" step="0.01" class="input mt-2" />
            <span class="mt-1 block text-xs font-normal text-muted-foreground">{{ signHint }}</span>
            <p v-if="txForm.errors.amount" class="field-error">{{ txForm.errors.amount }}</p>
          </label>
          <label class="label">Processing fee<input v-model.number="txForm.fee" type="number" min="0" step="0.01" class="input mt-2" /></label>
        </div>
        <label class="label">Note<input v-model="txForm.note" class="input mt-2" /></label>
        <DuplicateNotice :form="txForm" />
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="txForm.open = false">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="txForm.busy || !txForm.amount || !txForm.occurred_at">Add transaction</button>
        </div>
      </form>
    </Modal>

    <Modal :open="payoutForm.open" title="Add payout" description="Paid payouts are recorded as cash received. A later import of the same payout updates this entry." width="max-w-xl" @close="payoutForm.open = false">
      <form class="space-y-4" @submit.prevent="savePayout">
        <div class="grid gap-4 sm:grid-cols-3">
          <label class="label">Store
            <select v-model="payoutForm.channel_id" class="input mt-2"><option v-for="c in stores" :key="c.id" :value="c.id">{{ c.name }}</option></select>
          </label>
          <label class="label">Payout date<input v-model="payoutForm.paid_on" type="date" class="input mt-2" /></label>
          <label class="label">Status
            <select v-model="payoutForm.status" class="input mt-2"><option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option></select>
          </label>
          <label class="label">Total deposited
            <input v-model.number="payoutForm.total" type="number" step="0.01" class="input mt-2" />
            <p v-if="payoutForm.errors.total" class="field-error">{{ payoutForm.errors.total }}</p>
          </label>
          <label class="label">Charges<input v-model.number="payoutForm.charges" type="number" step="0.01" class="input mt-2" /></label>
          <label class="label">Refunds<input v-model.number="payoutForm.refunds" type="number" step="0.01" class="input mt-2" /></label>
          <label class="label">Adjustments<input v-model.number="payoutForm.adjustments" type="number" step="0.01" class="input mt-2" /></label>
          <label class="label">Sales tax<input v-model.number="payoutForm.tax" type="number" step="0.01" class="input mt-2" /></label>
          <label class="label">Fees<input v-model.number="payoutForm.fees" type="number" min="0" step="0.01" class="input mt-2" /></label>
        </div>
        <label class="label">Bank reference<input v-model="payoutForm.bank_reference" class="input mt-2" /></label>
        <DuplicateNotice :form="payoutForm" />
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="payoutForm.open = false">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="payoutForm.busy || !payoutForm.total || !payoutForm.paid_on">Add payout</button>
        </div>
      </form>
    </Modal>

    <StatementUploadModal :open="upload" :channel-id="filters.channel_id || null" @close="upload = false" @imported="load()" />
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref, watch } from 'vue';
import { Plus, Search, Trash2, TriangleAlert, Upload } from 'lucide-vue-next';
import ChannelBadge from '@/components/ChannelBadge.vue';
import Modal from '@/components/Modal.vue';
import Pagination from '@/components/Pagination.vue';
import StatementUploadModal from '@/components/StatementUploadModal.vue';
import api, { errorMessage, fieldErrors } from '@/lib/api';
import { date, dateTime, moneyExact, number, signedMoney } from '@/lib/format';
import { toast } from '@/lib/toast';
import { useWorkspaceStore } from '@/stores/workspace';

const workspace = useWorkspaceStore();

const tab = ref('transactions');
const filters = reactive({ channel_id: '', type: '', status: '' });
const search = ref('');
const rows = ref([]);
const summary = ref([]);
const meta = ref(null);
const loading = ref(false);
const upload = ref(false);

const today = () => new Date().toLocaleDateString('en-CA');
const txForm = reactive({ open: false, busy: false, errors: {}, allow_duplicate: false });
const payoutForm = reactive({ open: false, busy: false, errors: {}, allow_duplicate: false });

const stores = computed(() => workspace.channels.filter((c) => c.status !== 'disconnected'));
const types = computed(() => workspace.meta?.payment_types ?? []);
const statuses = computed(() => workspace.meta?.payout_statuses ?? []);
const statusLabel = (s) => s.replace('_', ' ').replace(/^./, (c) => c.toUpperCase());
const signHint = computed(() => {
  const sign = types.value.find((t) => t.value === txForm.type)?.sign;
  return sign === '+' ? 'Money in: entered as a positive amount.' : sign === '-' ? 'Money out: saved as a negative amount.' : 'Use a minus sign for money taken out.';
});

/** Where a record came from: typed in, imported, or typed in and later matched by an import. */
function SourceBadge({ record }) {
  const [label, style] = record.source === 'manual'
    ? ['Manual', 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300']
    : record.entered_manually
      ? ['Manual · matched', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300']
      : ['Imported', 'bg-muted text-muted-foreground'];
  return h('span', { class: `whitespace-nowrap rounded-full px-2 py-1 text-[11px] font-medium ${style}` }, label);
}
SourceBadge.props = ['record'];

/** Shown when the server says an entry matches an existing record; ticking the box saves it as a separate one. */
function DuplicateNotice({ form }) {
  if (!form.errors.duplicate) return null;
  return h('div', { class: 'space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200' }, [
    h('p', { class: 'flex gap-2' }, [h(TriangleAlert, { class: 'mt-0.5 size-3.5 shrink-0' }), form.errors.duplicate]),
    h('label', { class: 'flex items-center gap-2 font-medium' }, [
      h('input', { type: 'checkbox', checked: form.allow_duplicate, class: 'accent-amber-600', onChange: (e) => (form.allow_duplicate = e.target.checked) }),
      'Add anyway — it is a separate transaction',
    ]),
  ]);
}
DuplicateNotice.props = ['form'];

const params = (page) => ({
  page,
  channel_id: filters.channel_id || undefined,
  ...(tab.value === 'transactions'
    ? { type: filters.type || undefined, search: search.value.trim() || undefined }
    : { status: filters.status || undefined }),
});

async function load(page = 1) {
  loading.value = true;
  try {
    const { data } = await api.get(`/payments/${tab.value}`, { params: params(page) });
    rows.value = data.data;
    summary.value = data.summary ?? [];
    meta.value = { current_page: data.current_page, last_page: data.last_page, total: data.total };
  } finally {
    loading.value = false;
  }
}

function openTransaction() {
  Object.assign(txForm, {
    open: true, busy: false, errors: {}, allow_duplicate: false,
    channel_id: filters.channel_id || stores.value[0]?.id, type: 'charge', occurred_at: today(), order_ref: '', amount: null, fee: null, note: '',
  });
}

function openPayout() {
  Object.assign(payoutForm, {
    open: true, busy: false, errors: {}, allow_duplicate: false,
    channel_id: filters.channel_id || stores.value[0]?.id, paid_on: today(), status: 'paid', total: null,
    charges: null, refunds: null, adjustments: null, tax: null, fees: null, bank_reference: '',
  });
}

async function submit(form, url, payload, message) {
  form.busy = true;
  const confirmed = form.allow_duplicate && !!form.errors.duplicate;
  form.errors = {};
  try {
    await api.post(url, { ...payload, allow_duplicate: confirmed });
    toast(message);
    form.open = false;
    await Promise.all([load(), workspace.refreshCounts()]);
  } catch (error) {
    form.errors = fieldErrors(error);
    form.allow_duplicate = false;
    if (!Object.keys(form.errors).length) toast(errorMessage(error), 'error');
  } finally {
    form.busy = false;
  }
}

const saveTransaction = () => {
  const { channel_id, type, occurred_at, order_ref, amount, fee, note } = txForm;
  return submit(txForm, '/payments/transactions', { channel_id, type, occurred_at, order_ref: order_ref || null, amount, fee: fee || 0, note: note || null }, 'Transaction added');
};

const savePayout = () => {
  const f = payoutForm;
  return submit(f, '/payments/payouts', {
    channel_id: f.channel_id, paid_on: f.paid_on, status: f.status, total: f.total, charges: f.charges || 0, refunds: f.refunds || 0,
    adjustments: f.adjustments || 0, tax: f.tax || 0, fees: f.fees || 0, bank_reference: f.bank_reference || null,
  }, 'Payout added');
};

async function setStatus(payout, status) {
  try {
    await api.patch(`/payments/payouts/${payout.id}`, { status });
    toast(status === 'paid' ? 'Payout marked as paid and recorded as cash received' : 'Payout updated');
    await load(meta.value?.current_page ?? 1);
  } catch (error) {
    toast(errorMessage(error), 'error');
  }
}

async function remove(record) {
  const what = tab.value === 'transactions' ? 'transaction' : 'payout';
  if (!confirm(`Delete this ${what}?${record.source === 'import' ? ' It will come back if you import the same file again.' : ''}`)) return;
  try {
    await api.delete(`/payments/${tab.value}/${record.id}`);
    toast(`${what[0].toUpperCase()}${what.slice(1)} deleted`);
    await load(meta.value?.current_page ?? 1);
  } catch (error) {
    toast(errorMessage(error), 'error');
  }
}

let searchTimer;
watch(search, () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => load(), 300);
});
watch(() => [tab.value, filters.channel_id, filters.type, filters.status], () => load());
onMounted(() => load());
</script>
