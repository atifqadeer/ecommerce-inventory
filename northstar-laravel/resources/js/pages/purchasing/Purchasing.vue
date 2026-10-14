<template>
  <div class="space-y-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
      <div>
        <p class="eyebrow">Purchasing</p>
        <h1 class="page-title">Buying & suppliers</h1>
        <p class="mt-1 text-sm text-muted-foreground">Order stock from suppliers, receive it into inventory and keep track of what you owe.</p>
      </div>
      <div class="flex gap-2">
        <button v-if="tab === 'suppliers'" class="btn-primary" @click="openSupplier()"><Plus class="size-4" />Add supplier</button>
        <button v-else class="btn-primary" @click="openOrderForm()"><Plus class="size-4" />New purchase order</button>
      </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <section v-for="c in cards" :key="c.label" class="card p-5">
        <p class="text-sm text-muted-foreground">{{ c.label }}</p>
        <p class="mt-3 text-2xl font-semibold" :class="c.alert ? 'text-rose-600' : ''">{{ c.value }}</p>
        <p class="mt-2 text-xs text-muted-foreground">{{ c.note }}</p>
      </section>
    </div>

    <div class="flex flex-wrap gap-2 border-b border-border">
      <button
        v-for="[value, label] in [['orders', 'Purchase orders'], ['suppliers', 'Suppliers']]"
        :key="value"
        class="border-b-2 px-3 py-3 text-sm font-medium"
        :class="tab === value ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-muted-foreground hover:text-foreground'"
        @click="tab = value"
      >{{ label }}</button>
    </div>

    <template v-if="tab === 'orders'">
      <div class="card flex flex-wrap gap-2 p-3">
        <select v-model="filters.status" aria-label="Status" class="input w-auto">
          <option value="">All statuses</option>
          <option value="open">Open (not finished)</option>
          <option v-for="s in workspace.meta?.purchase_statuses ?? []" :key="s" :value="s">{{ label(s) }}</option>
        </select>
        <select v-model="filters.supplier_id" aria-label="Supplier" class="input w-auto">
          <option value="">All suppliers</option>
          <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
        <div class="relative ml-auto w-full sm:w-64">
          <Search class="absolute left-3 top-2.5 size-4 text-muted-foreground" />
          <input v-model="search" placeholder="PO number or SKU" class="input pl-9" />
        </div>
      </div>

      <div class="card overflow-hidden">
        <LoadingState v-if="loading && !orders.length" />
        <div v-else class="overflow-x-auto" :class="{ 'opacity-60': loading }">
          <table class="w-full min-w-[900px] text-left text-sm">
            <thead class="table-head">
              <tr>
                <th class="px-5 py-3">PO</th><th class="px-3 py-3">Supplier</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Ordered</th>
                <th class="px-3 py-3">Expected</th><th class="px-3 py-3">Received</th><th class="px-3 py-3 text-right">Total</th><th class="px-3 py-3">Payment</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="o in orders" :key="o.id" class="cursor-pointer hover:bg-muted/30" @click="openOrder(o.id)">
                <td class="px-5 py-3 font-semibold">{{ o.number }}</td>
                <td class="px-3 py-3">{{ o.supplier?.name }}</td>
                <td class="px-3 py-3"><StatusBadge :status="o.status" /></td>
                <td class="px-3 py-3 text-muted-foreground">{{ o.ordered_on ? date(`${o.ordered_on}T12:00:00`) : '—' }}</td>
                <td class="px-3 py-3" :class="isLate(o) ? 'font-medium text-rose-600' : 'text-muted-foreground'">{{ o.expected_on ? date(`${o.expected_on}T12:00:00`) : '—' }}{{ isLate(o) ? ' · late' : '' }}</td>
                <td class="px-3 py-3">
                  <div class="flex items-center gap-2">
                    <div class="h-1.5 w-16 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${o.units_ordered ? Math.round((o.units_received / o.units_ordered) * 100) : 0}%` }" /></div>
                    <span class="text-xs tabular-nums text-muted-foreground">{{ o.units_received }}/{{ o.units_ordered }}</span>
                  </div>
                </td>
                <td class="px-3 py-3 text-right font-semibold tabular-nums">{{ moneyExact(o.total) }}</td>
                <td class="px-3 py-3"><StatusBadge :status="o.status === 'cancelled' ? 'cancelled' : o.payment_status" /><span v-if="o.balance_due > 0" class="ml-2 text-xs tabular-nums text-muted-foreground">{{ moneyExact(o.balance_due) }} owed</span></td>
              </tr>
            </tbody>
          </table>
          <p v-if="!orders.length" class="py-12 text-center text-sm text-muted-foreground">{{ suppliers.length ? 'No purchase orders match these filters.' : 'Add a supplier, then create your first purchase order.' }}</p>
        </div>
      </div>
    </template>

    <div v-else class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[820px] text-left text-sm">
          <thead class="table-head">
            <tr>
              <th class="px-5 py-3">Supplier</th><th class="px-3 py-3">Contact</th><th class="px-3 py-3">Lead time</th><th class="px-3 py-3">Terms</th>
              <th class="px-3 py-3">Open orders</th><th class="px-3 py-3 text-right">Owed</th><th />
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="s in suppliers" :key="s.id">
              <td class="px-5 py-3 font-semibold">{{ s.name }}<p v-if="s.currency" class="text-xs font-normal text-muted-foreground">{{ s.currency }}</p></td>
              <td class="px-3 py-3 text-muted-foreground">{{ s.contact_name || '—' }}<p class="text-xs">{{ [s.email, s.phone].filter(Boolean).join(' · ') }}</p></td>
              <td class="px-3 py-3">{{ s.lead_time_days }} days</td>
              <td class="px-3 py-3">{{ s.payment_terms_days ? `Net ${s.payment_terms_days}` : 'On order' }}</td>
              <td class="px-3 py-3">{{ s.open_orders_count }}</td>
              <td class="px-3 py-3 text-right tabular-nums" :class="s.balance_due > 0 ? 'font-semibold text-rose-600' : 'text-muted-foreground'">{{ moneyExact(s.balance_due) }}</td>
              <td class="px-3 py-3 text-right">
                <button :aria-label="`Edit ${s.name}`" class="rounded-md p-1.5 text-muted-foreground hover:bg-muted" @click="openSupplier(s)"><Pencil class="size-4" /></button>
                <button :aria-label="`Delete ${s.name}`" class="rounded-md p-1.5 text-muted-foreground hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/40" @click="removeSupplier(s)"><Trash2 class="size-4" /></button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="!suppliers.length" class="py-12 text-center text-sm text-muted-foreground">No suppliers yet.</p>
      </div>
    </div>

    <PurchaseOrderForm :open="orderForm.open" :order="orderForm.order" :suppliers="suppliers" @close="orderForm.open = false" @saved="afterSave" />
    <PurchaseOrderDrawer :order="active" @close="active = null" @changed="afterChange" @edit="openOrderForm" @deleted="active = null; load()" />

    <Modal :open="supplierForm.open" :title="supplierForm.id ? 'Edit supplier' : 'Add supplier'" @close="supplierForm.open = false">
      <form class="space-y-4" @submit.prevent="saveSupplier">
        <label class="label">Name<input v-model="supplierForm.data.name" class="input mt-2" /><p v-if="supplierForm.errors.name" class="field-error">{{ supplierForm.errors.name }}</p></label>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="label">Contact person<input v-model="supplierForm.data.contact_name" class="input mt-2" /></label>
          <label class="label">Email<input v-model="supplierForm.data.email" type="email" class="input mt-2" /><p v-if="supplierForm.errors.email" class="field-error">{{ supplierForm.errors.email }}</p></label>
          <label class="label">Phone<input v-model="supplierForm.data.phone" class="input mt-2" /></label>
          <label class="label">Currency<input v-model="supplierForm.data.currency" maxlength="3" placeholder="USD" class="input mt-2 uppercase" /></label>
          <label class="label">Lead time (days)<input v-model.number="supplierForm.data.lead_time_days" type="number" min="0" class="input mt-2" /></label>
          <label class="label">Payment terms (days)<input v-model.number="supplierForm.data.payment_terms_days" type="number" min="0" class="input mt-2" /></label>
        </div>
        <label class="label">Address<textarea v-model="supplierForm.data.address" rows="2" class="input mt-2" /></label>
        <label class="label">Notes<textarea v-model="supplierForm.data.notes" rows="2" class="input mt-2" /></label>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="supplierForm.open = false">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="supplierForm.busy || !supplierForm.data.name">{{ supplierForm.id ? 'Save' : 'Add supplier' }}</button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import LoadingState from '@/components/LoadingState.vue';
import Modal from '@/components/Modal.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import api, { errorMessage, fieldErrors } from '@/lib/api';
import { date, moneyExact } from '@/lib/format';
import { toast } from '@/lib/toast';
import { useWorkspaceStore } from '@/stores/workspace';
import PurchaseOrderDrawer from './PurchaseOrderDrawer.vue';
import PurchaseOrderForm from './PurchaseOrderForm.vue';

const workspace = useWorkspaceStore();

const tab = ref('orders');
const filters = reactive({ status: '', supplier_id: '' });
const search = ref('');
const orders = ref([]);
const summary = ref(null);
const suppliers = ref([]);
const loading = ref(true);
const active = ref(null);
const orderForm = reactive({ open: false, order: null });
const blankSupplier = () => ({ name: '', contact_name: '', email: '', phone: '', currency: '', lead_time_days: 14, payment_terms_days: 30, address: '', notes: '' });
const supplierForm = reactive({ open: false, id: null, data: blankSupplier(), errors: {}, busy: false });

const today = new Date().toLocaleDateString('en-CA');
const label = (s) => s.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
const isLate = (o) => ['ordered', 'partially_received'].includes(o.status) && o.expected_on && o.expected_on < today;

const cards = computed(() => {
  const s = summary.value ?? { open_orders: 0, on_order: 0, owed_to_suppliers: 0, overdue: 0 };
  return [
    { label: 'Open purchase orders', value: s.open_orders, note: 'Ordered and waiting for stock' },
    { label: 'Stock on order', value: moneyExact(s.on_order), note: 'Value of units not yet arrived' },
    { label: 'Owed to suppliers', value: moneyExact(s.owed_to_suppliers), note: 'Unpaid balance on purchase orders', alert: s.owed_to_suppliers > 0 },
    { label: 'Late deliveries', value: s.overdue, note: 'Past their expected date', alert: s.overdue > 0 },
  ];
});

async function load() {
  loading.value = true;
  try {
    const params = { ...filters, search: search.value.trim() || undefined };
    Object.keys(params).forEach((k) => params[k] === '' && delete params[k]);
    const [{ data: list }, { data: supplierList }] = await Promise.all([api.get('/purchasing/orders', { params }), api.get('/purchasing/suppliers')]);
    orders.value = list.data;
    summary.value = list.summary;
    suppliers.value = supplierList;
  } finally {
    loading.value = false;
  }
}

async function openOrder(id) {
  active.value = (await api.get(`/purchasing/orders/${id}`)).data;
}

function openOrderForm(order = null) {
  if (!order && !suppliers.value.length) {
    toast('Add a supplier first');
    tab.value = 'suppliers';
    openSupplier();
    return;
  }
  Object.assign(orderForm, { open: true, order });
}

async function afterSave(order) {
  orderForm.open = false;
  toast(orderForm.order ? 'Purchase order saved' : `${order.number} created as a draft`);
  active.value = order;
  await load();
}

async function afterChange(order) {
  active.value = order;
  await Promise.all([load(), workspace.refreshCounts()]);
}

function openSupplier(supplier = null) {
  Object.assign(supplierForm, { open: true, id: supplier?.id ?? null, errors: {}, data: supplier ? { ...blankSupplier(), ...supplier } : blankSupplier() });
}

async function saveSupplier() {
  supplierForm.busy = true;
  supplierForm.errors = {};
  const d = supplierForm.data;
  const payload = { ...d, currency: d.currency ? d.currency.toUpperCase() : null };
  try {
    supplierForm.id ? await api.put(`/purchasing/suppliers/${supplierForm.id}`, payload) : await api.post('/purchasing/suppliers', payload);
    toast(supplierForm.id ? 'Supplier saved' : `${d.name} added`);
    supplierForm.open = false;
    await load();
  } catch (error) {
    supplierForm.errors = fieldErrors(error);
    if (!Object.keys(supplierForm.errors).length) toast(errorMessage(error), 'error');
  } finally {
    supplierForm.busy = false;
  }
}

async function removeSupplier(supplier) {
  if (!confirm(`Delete ${supplier.name}?`)) return;
  try {
    await api.delete(`/purchasing/suppliers/${supplier.id}`);
    toast('Supplier deleted');
    await load();
  } catch (error) {
    toast(Object.values(fieldErrors(error))[0] ?? errorMessage(error), 'error');
  }
}

let debounce;
watch(search, () => {
  clearTimeout(debounce);
  debounce = setTimeout(load, 300);
});
watch(filters, () => load());
onMounted(load);
</script>
