<template>
  <Modal :open="open" :title="order ? `Edit ${order.number}` : 'New purchase order'" description="Shipping and other costs are spread over the lines by value to give each unit's landed cost." width="max-w-3xl" @close="$emit('close')">
    <form class="space-y-5" novalidate @submit.prevent="submit">
      <div class="grid gap-4 sm:grid-cols-3">
        <label class="label">Supplier
          <select v-model="form.supplier_id" class="input mt-2">
            <option value="" disabled>Choose a supplier</option>
            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
          <p v-if="errors.supplier_id" class="field-error">{{ errors.supplier_id }}</p>
          <p v-else-if="!suppliers.length" class="mt-1 text-xs font-normal text-muted-foreground">Add a supplier first on the Suppliers tab.</p>
        </label>
        <label class="label">Expected delivery
          <input v-model="form.expected_on" type="date" class="input mt-2" />
          <span class="mt-1 block text-xs font-normal text-muted-foreground">Left empty: set from the supplier's lead time when ordered.</span>
        </label>
        <label class="label">Deliver to warehouse
          <input v-model="form.warehouse" list="po-warehouses" class="input mt-2" placeholder="Main warehouse" />
          <datalist id="po-warehouses"><option v-for="w in workspace.meta?.stock_warehouses ?? []" :key="w" :value="w" /></datalist>
        </label>
      </div>

      <div>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="label">Products</span>
          <div class="flex gap-3 text-xs font-medium">
            <button type="button" class="text-indigo-600 dark:text-indigo-400" @click="addLowStock">+ Add low-stock products</button>
            <button type="button" class="text-indigo-600 dark:text-indigo-400" @click="form.items.push(blankLine())">+ Add line</button>
          </div>
        </div>
        <div class="mt-2 overflow-x-auto">
          <table class="w-full min-w-[560px] text-sm">
            <thead class="text-left text-xs text-muted-foreground">
              <tr><th class="py-1.5 font-medium">Product</th><th class="w-24 py-1.5 font-medium">Quantity</th><th class="w-28 py-1.5 font-medium">Unit cost</th><th class="w-28 py-1.5 text-right font-medium">Line total</th><th class="w-8" /></tr>
            </thead>
            <tbody>
              <tr v-for="(line, i) in form.items" :key="i">
                <td class="py-1 pr-2">
                  <select v-model="line.product_id" aria-label="Product" class="input" @change="fillCost(line)">
                    <option value="" disabled>Select a product</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.sku }} · {{ p.name }} ({{ p.available }} in stock{{ p.incoming ? `, ${p.incoming} incoming` : '' }})</option>
                  </select>
                  <p v-if="errors[`items.${i}.product_id`] || errors[`items.${i}.quantity`]" class="field-error">{{ errors[`items.${i}.product_id`] || errors[`items.${i}.quantity`] }}</p>
                </td>
                <td class="py-1 pr-2"><input v-model.number="line.quantity" type="number" min="1" aria-label="Quantity" class="input" /></td>
                <td class="py-1 pr-2"><input v-model.number="line.unit_cost" type="number" min="0" step="0.01" aria-label="Unit cost" class="input" /></td>
                <td class="py-1 text-right tabular-nums">{{ moneyExact((line.quantity || 0) * (line.unit_cost || 0)) }}</td>
                <td class="py-1 text-right">
                  <button type="button" aria-label="Remove line" class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted" :disabled="form.items.length === 1" @click="form.items.splice(i, 1)"><Trash2 class="size-4" /></button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="errors.items" class="field-error">{{ errors.items }}</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-[1fr_1fr_1.4fr]">
        <label class="label">Shipping cost<input v-model.number="form.shipping_cost" type="number" min="0" step="0.01" class="input mt-2" /></label>
        <label class="label">Other costs <span class="font-normal text-muted-foreground">(duties, fees)</span><input v-model.number="form.other_costs" type="number" min="0" step="0.01" class="input mt-2" /></label>
        <dl class="self-end rounded-lg bg-muted/50 p-3 text-sm">
          <div class="flex justify-between"><dt class="text-muted-foreground">Products</dt><dd class="tabular-nums">{{ moneyExact(subtotal) }}</dd></div>
          <div class="mt-1 flex justify-between font-semibold"><dt>Total</dt><dd class="tabular-nums">{{ moneyExact(subtotal + (form.shipping_cost || 0) + (form.other_costs || 0)) }}</dd></div>
        </dl>
      </div>
      <label class="label">Notes<textarea v-model="form.notes" rows="2" class="input mt-2" /></label>

      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="$emit('close')">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="busy || !form.supplier_id">{{ order ? 'Save changes' : 'Create draft' }}</button>
      </div>
    </form>
  </Modal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Trash2 } from 'lucide-vue-next';
import Modal from '@/components/Modal.vue';
import api, { errorMessage, fieldErrors } from '@/lib/api';
import { moneyExact } from '@/lib/format';
import { toast } from '@/lib/toast';
import { useWorkspaceStore } from '@/stores/workspace';

const props = defineProps({ open: Boolean, order: Object, suppliers: Array });
const emit = defineEmits(['close', 'saved']);
const workspace = useWorkspaceStore();

const blankLine = () => ({ product_id: '', quantity: 1, unit_cost: 0 });
const form = reactive({ supplier_id: '', expected_on: '', warehouse: '', shipping_cost: 0, other_costs: 0, notes: '', items: [blankLine()] });
const products = ref([]);
const errors = ref({});
const busy = ref(false);

const subtotal = computed(() => form.items.reduce((sum, l) => sum + (l.quantity || 0) * (l.unit_cost || 0), 0));

watch(() => props.open, async (open) => {
  if (!open) return;
  errors.value = {};
  const o = props.order;
  Object.assign(form, o
    ? {
        supplier_id: o.supplier_id, expected_on: o.expected_on ?? '', warehouse: o.warehouse ?? '', shipping_cost: o.shipping_cost, other_costs: o.other_costs,
        notes: o.notes ?? '', items: o.items.map((i) => ({ product_id: i.product_id, quantity: i.quantity, unit_cost: i.unit_cost })),
      }
    : { supplier_id: props.suppliers?.length === 1 ? props.suppliers[0].id : '', expected_on: '', warehouse: '', shipping_cost: 0, other_costs: 0, notes: '', items: [blankLine()] });
  products.value = (await api.get('/products')).data;
});

function fillCost(line) {
  const product = products.value.find((p) => p.id === line.product_id);
  if (product && !line.unit_cost) line.unit_cost = product.unit_cost;
}

/** Products below their reorder point, with enough to reach twice that level once incoming stock arrives. */
function addLowStock() {
  const onOrder = new Set(form.items.map((l) => l.product_id));
  const low = products.value.filter((p) => p.is_low_stock && !onOrder.has(p.id));
  if (!low.length) {
    toast('No products are below their reorder point');
    return;
  }
  form.items = form.items.filter((l) => l.product_id);
  for (const p of low) {
    form.items.push({ product_id: p.id, quantity: Math.max(1, p.reorder_point * 2 - p.available - (p.incoming || 0)), unit_cost: p.unit_cost });
  }
}

async function submit() {
  busy.value = true;
  errors.value = {};
  const payload = { ...form, expected_on: form.expected_on || null, warehouse: form.warehouse || null, notes: form.notes || null, items: form.items.filter((l) => l.product_id) };
  try {
    const { data } = props.order ? await api.put(`/purchasing/orders/${props.order.id}`, payload) : await api.post('/purchasing/orders', payload);
    emit('saved', data);
  } catch (error) {
    errors.value = fieldErrors(error);
    if (!Object.keys(errors.value).length) toast(errorMessage(error), 'error');
  } finally {
    busy.value = false;
  }
}
</script>
