<template>
  <div class="space-y-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
      <div>
        <p class="eyebrow">Products</p>
        <h1 class="page-title">Product catalog</h1>
        <p class="mt-1 text-sm text-muted-foreground">Master SKUs, pricing, costs and channel listings.</p>
      </div>
      <button class="btn-primary" @click="openForm()"><Plus class="size-4" />Add product</button>
    </div>

    <div class="card flex flex-wrap items-center gap-2 p-3">
      <ChannelFilter v-model="filters.channel" />
      <select v-model="filters.warehouse" aria-label="Warehouse" class="input w-auto">
        <option value="">All warehouses</option>
        <option v-for="w in workspace.meta?.stock_warehouses ?? []" :key="w" :value="w">{{ w }}</option>
      </select>
      <div class="relative w-full sm:w-72">
        <Search class="absolute left-3 top-2.5 size-4 text-muted-foreground" />
        <input v-model="search" placeholder="Search SKU or product" class="input pl-9" />
      </div>
      <span class="ml-auto text-sm text-muted-foreground">{{ products.length }} products</span>
    </div>

    <div class="card overflow-hidden">
      <LoadingState v-if="loading && !products.length" />
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[860px] text-left text-sm">
          <thead class="table-head">
            <tr>
              <th class="px-5 py-3">Product</th><th class="px-3 py-3">Warehouse</th><th class="px-3 py-3 text-right">Price</th>
              <th class="px-3 py-3 text-right">Unit cost</th><th class="px-3 py-3 text-right">Margin</th><th class="px-3 py-3 text-right">On hand</th>
              <th class="px-3 py-3">Listed on</th><th class="px-3 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="p in products" :key="p.id" class="hover:bg-muted/30">
              <td class="px-5 py-3.5"><p class="font-medium">{{ p.name }}</p><p class="font-mono text-xs text-muted-foreground">{{ p.sku }}</p></td>
              <td class="px-3 py-3.5 text-muted-foreground">{{ p.warehouse }}</td>
              <td class="px-3 py-3.5 text-right tabular-nums">{{ moneyExact(p.price) }}</td>
              <td class="px-3 py-3.5 text-right tabular-nums text-muted-foreground">{{ moneyExact(p.unit_cost) }}</td>
              <td class="px-3 py-3.5 text-right tabular-nums font-medium text-emerald-600">{{ p.price > 0 ? (((p.price - p.unit_cost) / p.price) * 100).toFixed(0) : 0 }}%</td>
              <td class="px-3 py-3.5 text-right tabular-nums" :class="{ 'font-semibold text-rose-600': p.is_low_stock }">{{ p.on_hand }}</td>
              <td class="px-3 py-3.5"><div class="flex flex-wrap gap-1"><ChannelBadge v-for="l in p.listings" :key="l.id" :name="l.channel?.name" :platform="l.channel?.platform" /></div></td>
              <td class="px-3 py-3.5">
                <div class="flex justify-end gap-1">
                  <button :aria-label="`Edit ${p.sku}`" class="rounded-md p-1.5 text-muted-foreground hover:bg-muted" @click="openForm(p)"><Pencil class="size-4" /></button>
                  <button :aria-label="`Delete ${p.sku}`" class="rounded-md p-1.5 text-muted-foreground hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/40" @click="remove(p)"><Trash2 class="size-4" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="!products.length" class="py-12 text-center text-sm text-muted-foreground">No products yet. Add your first SKU to start tracking stock.</p>
      </div>
    </div>

    <Modal :open="form.open" :title="form.id ? 'Edit product' : 'Add product'" width="max-w-2xl" @close="form.open = false">
      <form class="space-y-4" novalidate @submit.prevent="save">
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="label">SKU<input v-model="form.data.sku" class="input mt-2 font-mono" /><p v-if="form.errors.sku" class="field-error">{{ form.errors.sku }}</p></label>
          <label class="label">Warehouse
            <select v-model="form.data.warehouse" class="input mt-2"><option v-for="w in workspace.meta?.warehouses ?? []" :key="w">{{ w }}</option></select>
          </label>
        </div>
        <label class="label">Product name<input v-model="form.data.name" class="input mt-2" /><p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p></label>
        <div class="grid gap-4 sm:grid-cols-4">
          <label class="label">Price<input v-model.number="form.data.price" type="number" min="0" step="0.01" class="input mt-2" /><p v-if="form.errors.price" class="field-error">{{ form.errors.price }}</p></label>
          <label class="label">Unit cost<input v-model.number="form.data.unit_cost" type="number" min="0" step="0.01" class="input mt-2" /><p v-if="form.errors.unit_cost" class="field-error">{{ form.errors.unit_cost }}</p></label>
          <label class="label">Reorder point<input v-model.number="form.data.reorder_point" type="number" min="0" class="input mt-2" /></label>
          <label class="label">{{ form.id ? 'On hand' : 'Opening stock' }}
            <input v-model.number="form.data.on_hand" type="number" min="0" class="input mt-2" :disabled="!!form.id" :title="form.id ? 'Use Inventory → Adjust stock to change quantities' : ''" />
          </label>
        </div>

        <div>
          <p class="label">Channel listings</p>
          <div class="mt-2 divide-y divide-border rounded-lg border border-border">
            <div v-for="l in form.data.listings" :key="l.channel_id" class="flex flex-wrap items-center gap-3 p-3">
              <label class="flex w-32 items-center gap-2 text-sm"><input v-model="l.enabled" type="checkbox" class="accent-indigo-600" />{{ channelName(l.channel_id) }}</label>
              <input v-model="l.external_id" :disabled="!l.enabled" placeholder="Listing / variant ID" aria-label="Listing ID" class="input min-w-40 flex-1" />
              <input v-model.number="l.allocated" :disabled="!l.enabled" type="number" min="0" aria-label="Allocated units" class="input w-24" />
            </div>
            <p v-if="!form.data.listings.length" class="p-3 text-sm text-muted-foreground">Connect a channel to list this product.</p>
          </div>
        </div>

        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="form.open = false">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="form.busy">{{ form.id ? 'Save changes' : 'Add product' }}</button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import ChannelFilter, { channelParams } from '@/components/ChannelFilter.vue';
import ChannelBadge from '@/components/ChannelBadge.vue';
import LoadingState from '@/components/LoadingState.vue';
import Modal from '@/components/Modal.vue';
import api, { errorMessage, fieldErrors } from '@/lib/api';
import { moneyExact } from '@/lib/format';
import { toast } from '@/lib/toast';
import { useWorkspaceStore } from '@/stores/workspace';

const workspace = useWorkspaceStore();
const products = ref([]);
const loading = ref(true);
const search = ref('');
const filters = reactive({ channel: '', warehouse: '' });
const form = reactive({ open: false, id: null, data: {}, errors: {}, busy: false });

const channelName = (id) => workspace.channels.find((c) => c.id === id)?.name;

async function load() {
  loading.value = true;
  try {
    const params = { ...channelParams(filters.channel), ...(filters.warehouse ? { warehouse: filters.warehouse } : {}), ...(search.value ? { search: search.value } : {}) };
    products.value = (await api.get('/products', { params })).data;
  } finally {
    loading.value = false;
  }
}

function openForm(product = null) {
  const listings = workspace.channels.map((c) => {
    const existing = product?.listings.find((l) => l.channel_id === c.id);
    return { channel_id: c.id, enabled: !!existing, external_id: existing?.external_id ?? '', allocated: existing?.allocated ?? 0 };
  });
  Object.assign(form, {
    open: true,
    id: product?.id ?? null,
    errors: {},
    data: {
      sku: product?.sku ?? '',
      name: product?.name ?? '',
      warehouse: product?.warehouse ?? workspace.meta?.warehouses?.[0] ?? 'East Coast',
      price: product?.price ?? 0,
      unit_cost: product?.unit_cost ?? 0,
      reorder_point: product?.reorder_point ?? 20,
      on_hand: product?.on_hand ?? 0,
      listings,
    },
  });
}

async function save() {
  form.busy = true;
  form.errors = {};
  const payload = {
    ...form.data,
    listings: form.data.listings.filter((l) => l.enabled).map(({ channel_id, external_id, allocated }) => ({ channel_id, external_id: external_id || null, allocated: allocated || 0 })),
  };
  try {
    if (form.id) await api.put(`/products/${form.id}`, payload);
    else await api.post('/products', payload);
    toast(form.id ? 'Product updated' : 'Product added');
    form.open = false;
    await load();
    workspace.refreshCounts();
  } catch (error) {
    form.errors = fieldErrors(error);
    if (!Object.keys(form.errors).length) toast(errorMessage(error), 'error');
  } finally {
    form.busy = false;
  }
}

async function remove(product) {
  if (!confirm(`Delete ${product.sku}? Its stock history and listings will be removed.`)) return;
  try {
    await api.delete(`/products/${product.id}`);
    toast('Product deleted');
    await load();
    workspace.refreshCounts();
  } catch (error) {
    toast(errorMessage(error), 'error');
  }
}

let debounce;
watch(filters, () => load());
watch(search, () => {
  clearTimeout(debounce);
  debounce = setTimeout(load, 300);
});

onMounted(load);
</script>
