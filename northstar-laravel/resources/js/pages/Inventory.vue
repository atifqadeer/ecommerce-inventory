<template>
  <div class="space-y-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
      <div>
        <p class="eyebrow">Inventory</p>
        <h1 class="page-title">Stock control center</h1>
        <p class="mt-1 text-sm text-muted-foreground">Manage master SKUs across every connected sales channel.</p>
      </div>
      <div class="flex gap-2">
        <button class="btn-primary" :disabled="!selected.length" @click="openAdjust"><Plus class="size-4" />Adjust stock {{ selected.length ? `(${selected.length})` : '' }}</button>
        <button class="btn-secondary" @click="importOpen = true"><Upload class="size-4" />Import stock</button>
        <button class="btn-secondary" @click="download('/products/export', params(), 'northstar-inventory.csv')"><Download class="size-4" />Export CSV</button>
      </div>
    </div>

    <div class="card flex flex-wrap items-center gap-2 p-3">
      <Filter class="ml-1 size-4 text-muted-foreground" />
      <select v-model="filters.warehouse" aria-label="Warehouse" class="input w-auto">
        <option value="">All warehouses</option>
        <option v-for="w in workspace.meta?.warehouses ?? []" :key="w">{{ w }}</option>
      </select>
      <select v-model="filters.channel_id" aria-label="Channel" class="input w-auto">
        <option value="">All channels</option>
        <option v-for="c in workspace.channels" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <label class="ml-auto flex items-center gap-2 text-sm"><input v-model="filters.low_stock" type="checkbox" class="accent-indigo-600" />Low stock only</label>
      <div class="relative w-full sm:w-56">
        <Search class="absolute left-3 top-2.5 size-4 text-muted-foreground" />
        <input v-model="search" placeholder="Search SKU or product" class="input pl-9" />
      </div>
    </div>

    <div class="card overflow-hidden">
      <LoadingState v-if="loading && !rows.length" />
      <div v-else class="overflow-x-auto" :class="{ 'opacity-60': loading }">
        <table class="w-full min-w-[980px] text-left text-sm">
          <thead class="table-head">
            <tr>
              <th class="w-10 px-4 py-3"><input type="checkbox" aria-label="Select all" class="accent-indigo-600" :checked="allSelected" @change="selected = $event.target.checked ? rows.map((r) => r.id) : []" /></th>
              <th class="px-3 py-3">Image</th><th class="px-3 py-3">SKU</th><th class="px-3 py-3">Product name</th>
              <th class="px-3 py-3">Total stock</th><th class="px-3 py-3">Available</th><th class="px-3 py-3">Reserved</th><th class="px-3 py-3" title="Units on purchase orders that have not arrived yet">Incoming</th>
              <th v-for="c in workspace.channels" :key="c.id" class="px-3 py-3"><ChannelBadge :name="c.name" :platform="c.platform" /></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="r in rows" :key="r.id" class="cursor-pointer hover:bg-muted/30" @click="openProduct(r.id)">
              <td class="px-4 py-4" @click.stop><input v-model="selected" type="checkbox" :value="r.id" :aria-label="`Select ${r.sku}`" class="accent-indigo-600" /></td>
              <td class="px-3 py-4"><div class="flex size-9 items-center justify-center rounded-lg bg-indigo-50 text-xs font-bold text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300">{{ initials(r.name) }}</div></td>
              <td class="px-3 py-4 font-mono text-xs font-semibold">{{ r.sku }}</td>
              <td class="px-3 py-4 font-medium">{{ r.name }}<div class="mt-1 text-xs text-muted-foreground">{{ r.warehouse }}</div></td>
              <td class="px-3 py-4 font-semibold">{{ r.on_hand }}</td>
              <td class="px-3 py-4 font-semibold" :class="{ 'text-rose-600': r.is_low_stock }">{{ r.available }}</td>
              <td class="px-3 py-4 text-muted-foreground">{{ r.reserved }}</td>
              <td class="px-3 py-4" :class="r.incoming ? 'font-medium text-blue-600 dark:text-blue-400' : 'text-muted-foreground'">{{ r.incoming ? `+${r.incoming}` : '—' }}</td>
              <td v-for="c in workspace.channels" :key="c.id" class="px-3 py-4">{{ allocation(r, c.id) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-if="!rows.length" class="py-12 text-center text-sm text-muted-foreground">No products match these filters.</p>
      </div>
    </div>

    <Drawer :open="!!active" :label="active?.name" @close="active = null">
      <div v-if="active" class="p-6">
        <div class="flex items-start justify-between">
          <div>
            <p class="font-mono text-xs text-indigo-600 dark:text-indigo-400">{{ active.sku }}</p>
            <h2 class="mt-1 text-xl font-semibold">{{ active.name }}</h2>
            <p class="mt-1 text-xs text-muted-foreground">{{ active.warehouse }} · Reorder at {{ active.reorder_point }}</p>
          </div>
          <button aria-label="Close drawer" @click="active = null"><X class="size-5" /></button>
        </div>
        <div class="mt-6 grid grid-cols-3 gap-3">
          <div v-for="[label, value] in [['Total', active.on_hand], ['Available', active.available], ['Reserved', active.reserved]]" :key="label" class="rounded-lg bg-muted/50 p-3">
            <p class="text-xs text-muted-foreground">{{ label }}</p>
            <p class="mt-1 text-lg font-semibold">{{ value }}</p>
          </div>
        </div>
        <button class="btn-secondary mt-4 w-full" @click="selected = [active.id]; openAdjust()"><Plus class="size-4" />Adjust stock</button>

        <section class="mt-8">
          <h3 class="flex items-center gap-2 font-semibold"><History class="size-4" />Stock movement history</h3>
          <div class="mt-4 border-l border-border pl-5">
            <div v-for="(m, i) in active.movements" :key="m.id" class="relative" :class="{ 'pb-6': i < active.movements.length - 1 }">
              <span class="absolute -left-[25px] top-1 size-2 rounded-full" :class="m.quantity > 0 ? 'bg-indigo-600' : 'bg-amber-500'" />
              <p class="text-sm font-medium">{{ m.quantity > 0 ? '+' : '' }}{{ m.quantity }} units · {{ m.reason }}</p>
              <p class="text-xs text-muted-foreground">{{ dateTime(m.created_at) }}<template v-if="m.note"> · {{ m.note }}</template></p>
            </div>
            <p v-if="!active.movements.length" class="text-sm text-muted-foreground">No movements yet.</p>
          </div>
        </section>

        <section class="mt-8">
          <h3 class="font-semibold">SKU-to-channel listing mapping</h3>
          <div class="mt-3 divide-y divide-border rounded-lg border border-border">
            <div v-for="l in active.listings" :key="l.id" class="flex items-center justify-between p-3">
              <ChannelBadge :name="l.channel?.name" :platform="l.channel?.platform" />
              <span class="font-mono text-xs text-muted-foreground">{{ l.external_id || 'Not mapped' }} · {{ l.allocated }} allocated</span>
            </div>
            <p v-if="!active.listings.length" class="p-3 text-sm text-muted-foreground">Not listed on any channel.</p>
          </div>
        </section>
      </div>
    </Drawer>

    <Modal :open="adjust.open" title="Stock adjustment" :description="`Adjusting ${selected.length} selected SKU${selected.length > 1 ? 's' : ''}.`" width="max-w-md" @close="adjust.open = false">
      <form class="space-y-4" @submit.prevent="applyAdjustment">
        <label class="label">Reason
          <select v-model="adjust.reason" class="input mt-2">
            <option v-for="r in workspace.meta?.adjustment_reasons ?? []" :key="r">{{ r }}</option>
          </select>
        </label>
        <label class="label">Quantity
          <input v-model.number="adjust.quantity" type="number" placeholder="e.g. 24 or -3" class="input mt-2" />
          <span class="mt-1 block text-xs font-normal text-muted-foreground">Positive adds stock, negative removes it.</span>
          <p v-if="adjust.errors.quantity" class="field-error">{{ adjust.errors.quantity }}</p>
        </label>
        <label class="label">Note <span class="font-normal text-muted-foreground">(optional)</span>
          <input v-model="adjust.note" class="input mt-2" />
        </label>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="adjust.open = false">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="adjust.busy || !adjust.quantity">Apply adjustment</button>
        </div>
      </form>
    </Modal>

    <StockImportModal :open="importOpen" @close="importOpen = false" @imported="load(); workspace.refresh()" />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Download, Filter, History, Plus, Search, Upload, X } from 'lucide-vue-next';
import ChannelBadge from '@/components/ChannelBadge.vue';
import Drawer from '@/components/Drawer.vue';
import LoadingState from '@/components/LoadingState.vue';
import Modal from '@/components/Modal.vue';
import StockImportModal from '@/components/StockImportModal.vue';
import api, { download, errorMessage, fieldErrors } from '@/lib/api';
import { dateTime, initials } from '@/lib/format';
import { toast } from '@/lib/toast';
import { useWorkspaceStore } from '@/stores/workspace';

const route = useRoute();
const workspace = useWorkspaceStore();

const filters = reactive({ warehouse: '', channel_id: '', low_stock: !!route.query.low_stock, search: '' });
const search = ref('');
const rows = ref([]);
const loading = ref(true);
const selected = ref([]);
const active = ref(null);
const importOpen = ref(false);
const adjust = reactive({ open: false, reason: 'Cycle count correction', quantity: null, note: '', busy: false, errors: {} });

const allSelected = computed(() => rows.value.length > 0 && selected.value.length === rows.value.length);
const params = () => Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== false).map(([k, v]) => [k, v === true ? 1 : v]));
const allocation = (row, channelId) => row.listings.find((l) => l.channel_id === channelId)?.allocated ?? '—';

async function load() {
  loading.value = true;
  try {
    rows.value = (await api.get('/products', { params: params() })).data;
    selected.value = selected.value.filter((id) => rows.value.some((r) => r.id === id));
  } finally {
    loading.value = false;
  }
}

async function openProduct(id) {
  active.value = (await api.get(`/products/${id}`)).data;
}

function openAdjust() {
  Object.assign(adjust, { open: true, quantity: null, note: '', errors: {} });
}

async function applyAdjustment() {
  adjust.busy = true;
  adjust.errors = {};
  try {
    const { data } = await api.post('/products/adjust', { product_ids: selected.value, reason: adjust.reason, quantity: adjust.quantity, note: adjust.note || null });
    toast(data.message);
    adjust.open = false;
    selected.value = [];
    await load();
    if (active.value) await openProduct(active.value.id);
    workspace.refreshCounts();
  } catch (error) {
    adjust.errors = fieldErrors(error);
    if (!Object.keys(adjust.errors).length) toast(errorMessage(error), 'error');
  } finally {
    adjust.busy = false;
  }
}

let debounce;
watch(search, (value) => {
  clearTimeout(debounce);
  debounce = setTimeout(() => (filters.search = value.trim()), 300);
});
watch(filters, load);

onMounted(load);
</script>
