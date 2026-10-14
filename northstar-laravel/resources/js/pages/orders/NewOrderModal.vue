<template>
  <Modal :open="open" title="New order" description="Record a manual or phone order. Stock is reserved until it ships." width="max-w-2xl" @close="$emit('close')">
    <form class="space-y-4" novalidate @submit.prevent="submit">
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="label">Channel
          <select v-model="form.channel_id" class="input mt-2">
            <option v-for="c in activeChannels" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <p v-if="errors.channel_id" class="field-error">{{ errors.channel_id }}</p>
        </label>
        <label class="label">Fulfillment
          <select v-model="form.fulfillment" class="input mt-2"><option>FBM</option><option>FBA</option></select>
        </label>
        <label class="label sm:col-span-2">Order number <span class="font-normal text-muted-foreground">(optional, as shown on the platform, e.g. #2542614)</span>
          <input v-model="form.number" class="input mt-2" placeholder="Leave empty to number it automatically" />
          <span class="mt-1 block text-xs font-normal text-muted-foreground">With the platform's number, a later import of this order updates it instead of adding a duplicate.</span>
          <p v-if="errors.number" class="field-error">{{ errors.number }}</p>
        </label>
        <label class="label">Customer name
          <input v-model="form.customer_name" class="input mt-2" />
          <p v-if="errors.customer_name" class="field-error">{{ errors.customer_name }}</p>
        </label>
        <label class="label">Customer email
          <input v-model="form.customer_email" type="email" class="input mt-2" />
          <p v-if="errors.customer_email" class="field-error">{{ errors.customer_email }}</p>
        </label>
      </div>
      <label class="label">Shipping address
        <textarea v-model="form.shipping_address" rows="2" class="input mt-2" />
      </label>

      <div>
        <div class="flex items-center justify-between">
          <span class="label">Items</span>
          <button type="button" class="text-xs font-medium text-indigo-600 dark:text-indigo-400" @click="form.items.push({ product_id: '', quantity: 1 })">+ Add item</button>
        </div>
        <div v-for="(item, i) in form.items" :key="i" class="mt-2 flex gap-2">
          <select v-model="item.product_id" aria-label="Product" class="input flex-1">
            <option value="" disabled>Select a product</option>
            <option v-for="p in products" :key="p.id" :value="p.id" :disabled="p.available === 0">{{ p.sku }} · {{ p.name }} ({{ p.available }} available)</option>
          </select>
          <input v-model.number="item.quantity" type="number" min="1" aria-label="Quantity" class="input w-20" />
          <button type="button" aria-label="Remove item" class="rounded-lg px-2 text-muted-foreground hover:bg-muted" :disabled="form.items.length === 1" @click="form.items.splice(i, 1)"><Trash2 class="size-4" /></button>
        </div>
        <p v-for="(message, key) in itemErrors" :key="key" class="field-error">{{ message }}</p>
      </div>

      <div class="flex items-center justify-between border-t border-border pt-4">
        <label class="flex items-center gap-2 text-sm">Shipping cost
          <input v-model.number="form.shipping_cost" type="number" min="0" step="0.01" class="input w-24" />
        </label>
        <p class="text-sm">Subtotal <span class="ml-1 text-base font-semibold">{{ moneyExact(subtotal) }}</span></p>
      </div>

      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="$emit('close')">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="busy || !form.channel_id"><LoaderCircle v-if="busy" class="size-4 animate-spin" />Create order</button>
      </div>
    </form>
  </Modal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { LoaderCircle, Trash2 } from 'lucide-vue-next';
import Modal from '@/components/Modal.vue';
import api, { errorMessage, fieldErrors } from '@/lib/api';
import { moneyExact } from '@/lib/format';
import { toast } from '@/lib/toast';
import { useWorkspaceStore } from '@/stores/workspace';

const props = defineProps({ open: Boolean });
const emit = defineEmits(['close', 'created']);
const workspace = useWorkspaceStore();

const blank = () => ({ channel_id: '', number: '', fulfillment: 'FBM', customer_name: '', customer_email: '', shipping_address: '', shipping_cost: 0, items: [{ product_id: '', quantity: 1 }] });
const form = reactive(blank());
const products = ref([]);
const errors = ref({});
const busy = ref(false);

const activeChannels = computed(() => workspace.channels.filter((c) => c.status !== 'disconnected'));
const subtotal = computed(() => form.items.reduce((sum, item) => sum + (products.value.find((p) => p.id === item.product_id)?.price ?? 0) * (item.quantity || 0), 0));
const itemErrors = computed(() => Object.fromEntries(Object.entries(errors.value).filter(([key]) => key.startsWith('items'))));

watch(() => props.open, async (open) => {
  if (!open) return;
  Object.assign(form, blank(), { channel_id: activeChannels.value[0]?.id ?? '' });
  errors.value = {};
  products.value = (await api.get('/products')).data;
});

async function submit() {
  busy.value = true;
  errors.value = {};
  try {
    const items = form.items.filter((i) => i.product_id);
    const { data } = await api.post('/orders', { ...form, number: form.number.trim() || null, items });
    emit('created', data);
  } catch (error) {
    errors.value = fieldErrors(error);
    if (!Object.keys(errors.value).length) toast(errorMessage(error), 'error');
  } finally {
    busy.value = false;
  }
}
</script>
