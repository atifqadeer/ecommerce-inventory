<template>
  <div class="space-y-6">
    <div>
      <p class="eyebrow">Reports</p>
      <h1 class="page-title">Exports & expenses</h1>
      <p class="mt-1 text-sm text-muted-foreground">Download your data and record operating costs that feed the P&amp;L.</p>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
      <section v-for="r in reports" :key="r.title" class="card flex flex-col p-5">
        <div class="flex size-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-300"><component :is="r.icon" class="size-5" /></div>
        <h2 class="mt-4 font-semibold">{{ r.title }}</h2>
        <p class="mt-1 flex-1 text-sm text-muted-foreground">{{ r.description }}</p>
        <button class="btn-secondary mt-4" :disabled="busy === r.title" @click="run(r)"><FileDown class="size-4" />Download CSV</button>
      </section>
    </div>

    <section class="card">
      <div class="flex flex-col gap-3 border-b border-border p-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="font-semibold">Operating expenses</h2>
          <p class="mt-1 text-xs text-muted-foreground">Advertising, payment processing and software costs.</p>
        </div>
        <div class="flex gap-2">
          <select v-model="category" aria-label="Category" class="input w-auto">
            <option value="">All categories</option>
            <option v-for="c in workspace.meta?.expense_categories ?? []" :key="c.value" :value="c.value">{{ c.label }}</option>
          </select>
          <button class="btn-primary" @click="openForm"><Plus class="size-4" />Add expense</button>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-left text-sm">
          <thead class="table-head">
            <tr><th class="px-5 py-3">Date</th><th class="px-3 py-3">Category</th><th class="px-3 py-3">Description</th><th class="px-3 py-3">Channel</th><th class="px-3 py-3 text-right">Amount</th><th /></tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="e in expenses" :key="e.id">
              <td class="px-5 py-3 text-muted-foreground">{{ date(`${e.incurred_on}T12:00:00`) }}</td>
              <td class="px-3 py-3 font-medium">{{ categoryLabel(e.category) }}</td>
              <td class="px-3 py-3 text-muted-foreground">{{ e.description || '—' }}</td>
              <td class="px-3 py-3"><ChannelBadge v-if="e.channel" :name="e.channel.name" :platform="e.channel.platform" /><span v-else class="text-xs text-muted-foreground">Shared</span></td>
              <td class="px-3 py-3 text-right font-semibold tabular-nums">{{ moneyExact(e.amount) }}</td>
              <td class="px-3 py-3 text-right"><button :aria-label="`Delete expense`" class="rounded-md p-1.5 text-muted-foreground hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/40" @click="remove(e)"><Trash2 class="size-4" /></button></td>
            </tr>
          </tbody>
        </table>
        <p v-if="!expenses.length" class="py-10 text-center text-sm text-muted-foreground">No expenses recorded.</p>
      </div>
      <Pagination :meta="meta" @change="load" />
    </section>

    <Modal :open="form.open" title="Add expense" width="max-w-md" @close="form.open = false">
      <form class="space-y-4" @submit.prevent="save">
        <label class="label">Category
          <select v-model="form.category" class="input mt-2"><option v-for="c in workspace.meta?.expense_categories ?? []" :key="c.value" :value="c.value">{{ c.label }}</option></select>
        </label>
        <div class="grid grid-cols-2 gap-4">
          <label class="label">Amount<input v-model.number="form.amount" type="number" min="0.01" step="0.01" class="input mt-2" /><p v-if="form.errors.amount" class="field-error">{{ form.errors.amount }}</p></label>
          <label class="label">Date<input v-model="form.incurred_on" type="date" class="input mt-2" /></label>
        </div>
        <label class="label">Channel
          <select v-model="form.channel_id" class="input mt-2"><option value="">Shared across channels</option><option v-for="c in workspace.channels" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        </label>
        <label class="label">Description<input v-model="form.description" class="input mt-2" /></label>
        <div v-if="form.errors.duplicate" class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
          <p>{{ form.errors.duplicate }}</p>
          <label class="flex items-center gap-2 font-medium"><input v-model="form.allow_duplicate" type="checkbox" class="accent-amber-600" />Add anyway — it is a separate cost</label>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="form.open = false">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="form.busy || !form.amount">Add expense</button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { FileDown, Package, Plus, ShoppingBag, Trash2, WalletCards } from 'lucide-vue-next';
import ChannelBadge from '@/components/ChannelBadge.vue';
import Modal from '@/components/Modal.vue';
import Pagination from '@/components/Pagination.vue';
import api, { download, errorMessage, fieldErrors } from '@/lib/api';
import { date, moneyExact } from '@/lib/format';
import { toast } from '@/lib/toast';
import { useWorkspaceStore } from '@/stores/workspace';

const workspace = useWorkspaceStore();

const reports = [
  { title: 'Orders', icon: ShoppingBag, description: 'Every order with channel, status, fees, COGS and net profit.', url: '/orders/export', file: 'northstar-orders.csv' },
  { title: 'Inventory', icon: Package, description: 'Stock on hand, available and reserved, with channel allocations.', url: '/products/export', file: 'northstar-inventory.csv' },
  { title: 'Profit & loss', icon: WalletCards, description: 'Last 6 months P&L by month, with comparison to the prior period.', url: '/finance/profit-loss/export', params: { period: 'last_6_months', group: 'month' }, file: 'northstar-profit-loss.csv' },
];

const busy = ref(null);
const expenses = ref([]);
const meta = ref(null);
const category = ref('');
const form = reactive({ open: false, busy: false, errors: {}, allow_duplicate: false, category: 'advertising', amount: null, incurred_on: '', channel_id: '', description: '' });

const categoryLabel = (value) => workspace.meta?.expense_categories.find((c) => c.value === value)?.label ?? value;

async function run(report) {
  busy.value = report.title;
  try {
    await download(report.url, report.params, report.file);
  } catch (error) {
    toast(errorMessage(error), 'error');
  } finally {
    busy.value = null;
  }
}

async function load(page = 1) {
  const { data } = await api.get('/expenses', { params: { page, category: category.value || undefined } });
  expenses.value = data.data;
  meta.value = { current_page: data.current_page, last_page: data.last_page, total: data.total };
}

function openForm() {
  Object.assign(form, { open: true, errors: {}, allow_duplicate: false, category: 'advertising', amount: null, incurred_on: new Date().toLocaleDateString('en-CA'), channel_id: '', description: '' });
}

async function save() {
  form.busy = true;
  try {
    const { category: cat, amount, incurred_on, channel_id, description } = form;
    const allow_duplicate = form.allow_duplicate && !!form.errors.duplicate;
    await api.post('/expenses', { category: cat, amount, incurred_on, channel_id: channel_id || null, description: description || null, allow_duplicate });
    toast('Expense added');
    form.errors = {};
    form.open = false;
    await load();
  } catch (error) {
    form.errors = fieldErrors(error);
    form.allow_duplicate = false;
    if (!Object.keys(form.errors).length) toast(errorMessage(error), 'error');
  } finally {
    form.busy = false;
  }
}

async function remove(expense) {
  if (!confirm('Delete this expense?')) return;
  try {
    await api.delete(`/expenses/${expense.id}`);
    toast('Expense deleted');
    await load(meta.value?.current_page ?? 1);
  } catch (error) {
    toast(Object.values(fieldErrors(error))[0] ?? errorMessage(error), 'error');
  }
}

watch(category, () => load());
onMounted(() => load());
</script>
