<template>
  <div class="space-y-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
      <div>
        <p class="eyebrow">Orders</p>
        <h1 class="page-title">Unified order management</h1>
        <p class="mt-1 text-sm text-muted-foreground">Review, fulfill, and reconcile orders from every connected channel.</p>
      </div>
      <div class="flex gap-2">
        <button class="btn-secondary" :disabled="exporting" @click="exportCsv"><Download class="size-4" />Export CSV</button>
        <button class="btn-primary" @click="showCreate = true"><Plus class="size-4" />New order</button>
      </div>
    </div>

    <div class="flex flex-wrap gap-2 border-b border-border">
      <button
        v-for="t in tabs"
        :key="t.value"
        class="border-b-2 px-3 py-3 text-sm font-medium"
        :class="filters.status === t.value ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-muted-foreground hover:text-foreground'"
        @click="filters.status = t.value"
      >
        {{ t.label }} <span class="ml-1 rounded-full bg-muted px-1.5 py-0.5 text-[10px]">{{ counts[t.value || 'all'] ?? 0 }}</span>
      </button>
    </div>

    <div class="card flex flex-wrap gap-2 p-3">
      <ChannelFilter v-model="filters.channel" />

      <select v-model="filters.fulfillment" aria-label="Fulfillment" class="input w-auto">
        <option value="">All fulfillment types</option>
        <option>FBA</option>
        <option>FBM</option>
      </select>
      <input v-model="filters.date" type="date" aria-label="Order date" class="input w-auto" />
      <div class="relative ml-auto w-full sm:w-64">
        <Search class="absolute left-3 top-2.5 size-4 text-muted-foreground" />
        <input v-model="search" placeholder="Search order or customer" class="input pl-9" />
      </div>
    </div>

    <div class="card overflow-hidden">
      <LoadingState v-if="loading && !orders.length" />
      <div v-else class="overflow-x-auto" :class="{ 'opacity-60': loading }">
        <table class="w-full min-w-[850px] text-left text-sm">
          <thead class="table-head">
            <tr>
              <th class="px-5 py-3">Order</th><th class="px-3 py-3">Channel</th><th class="px-3 py-3">Customer</th><th class="px-3 py-3">Date</th>
              <th class="px-3 py-3">Status</th><th class="px-3 py-3">Fulfillment</th><th class="px-3 py-3 text-right">Total</th><th />
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="o in orders" :key="o.id" class="cursor-pointer hover:bg-muted/30" @click="router.push({ name: 'order', params: { id: o.id } })">
              <td class="px-5 py-4"><p class="font-mono text-xs font-semibold">{{ o.number }}</p><p class="mt-1 text-xs text-muted-foreground">{{ o.item_count }} item{{ o.item_count == 1 ? '' : 's' }}</p></td>
              <td class="px-3 py-4"><ChannelBadge :name="o.channel?.name" :platform="o.channel?.platform" /></td>
              <td class="px-3 py-4 font-medium">{{ o.customer_name }}</td>
              <td class="px-3 py-4 text-muted-foreground">{{ date(o.placed_at) }}</td>
              <td class="px-3 py-4"><StatusBadge :status="o.status" /></td>
              <td class="px-3 py-4"><span class="rounded-md border border-border px-2 py-1 text-xs">{{ o.fulfillment }}</span></td>
              <td class="px-3 py-4 text-right font-semibold">{{ moneyExact(o.subtotal) }}</td>
              <td class="px-3 py-4"><ChevronRight class="size-4 text-muted-foreground" /></td>
            </tr>
          </tbody>
        </table>
        <p v-if="!orders.length" class="py-12 text-center text-sm text-muted-foreground">No orders match these filters.</p>
      </div>
      <Pagination :meta="meta" @change="load" />
    </div>

    <NewOrderModal :open="showCreate" @close="showCreate = false" @created="onCreated" />
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ChevronRight, Download, Plus, Search } from 'lucide-vue-next';
import ChannelFilter, { channelParams } from '@/components/ChannelFilter.vue';
import ChannelBadge from '@/components/ChannelBadge.vue';
import LoadingState from '@/components/LoadingState.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import NewOrderModal from './NewOrderModal.vue';
import api, { download } from '@/lib/api';
import { date, moneyExact } from '@/lib/format';
import { toast } from '@/lib/toast';
import { useWorkspaceStore } from '@/stores/workspace';

const router = useRouter();
const workspace = useWorkspaceStore();

const tabs = [
  { value: '', label: 'All orders' },
  { value: 'paid', label: 'Paid' },
  { value: 'processing', label: 'Processing' },
  { value: 'shipped', label: 'Shipped' },
  { value: 'delivered', label: 'Delivered' },
  { value: 'cancelled', label: 'Cancelled' },
];

const filters = reactive({ status: '', channel: '', fulfillment: '', date: '', search: '' });
const search = ref('');
const orders = ref([]);
const meta = ref(null);
const counts = ref({});
const loading = ref(true);
const exporting = ref(false);
const showCreate = ref(false);

const params = () => {
  const { channel, ...rest } = filters;
  return { ...Object.fromEntries(Object.entries(rest).filter(([, v]) => v !== '')), ...channelParams(channel) };
};

async function load(page = 1) {
  loading.value = true;
  try {
    const { data } = await api.get('/orders', { params: { ...params(), page } });
    orders.value = data.data;
    meta.value = data.meta;
    counts.value = data.status_counts;
  } finally {
    loading.value = false;
  }
}

let debounce;
watch(search, (value) => {
  clearTimeout(debounce);
  debounce = setTimeout(() => (filters.search = value.trim()), 300);
});
watch(filters, () => load(1));

async function exportCsv() {
  exporting.value = true;
  try {
    await download('/orders/export', params(), 'northstar-orders.csv');
  } finally {
    exporting.value = false;
  }
}

function onCreated(order) {
  showCreate.value = false;
  toast(`Order ${order.number} created`);
  workspace.refreshCounts();
  router.push({ name: 'order', params: { id: order.id } });
}

onMounted(() => load());
</script>
