<template>
  <Drawer :open="!!order" :label="order?.number" width="max-w-2xl" @close="$emit('close')">
    <div v-if="order" class="space-y-6 p-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-xs font-medium uppercase tracking-widest text-indigo-600 dark:text-indigo-400">Purchase order</p>
          <h2 class="mt-1 text-xl font-semibold">{{ order.number }} · {{ order.supplier?.name }}</h2>
          <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <StatusBadge :status="order.status" /><StatusBadge :status="order.payment_status" />
            <span v-if="order.ordered_on">Ordered {{ date(`${order.ordered_on}T12:00:00`) }}</span>
            <span v-if="order.expected_on" :class="{ 'font-medium text-rose-600': overdue }">· Expected {{ date(`${order.expected_on}T12:00:00`) }}{{ overdue ? ' (late)' : '' }}</span>
            <span v-if="order.warehouse">· to {{ order.warehouse }}</span>
          </div>
        </div>
        <button aria-label="Close" @click="$emit('close')"><X class="size-5 text-muted-foreground" /></button>
      </div>

      <div class="flex flex-wrap gap-2">
        <button v-if="order.status === 'draft'" class="btn-primary" :disabled="busy" @click="act('order', {}, 'Marked as ordered')"><Send class="size-4" />Mark as ordered</button>
        <button v-if="canReceive" class="btn-primary" @click="openReceive"><PackageCheck class="size-4" />Receive stock</button>
        <button v-if="order.status !== 'cancelled' && order.balance_due > 0" class="btn-secondary" @click="openPayment"><Banknote class="size-4" />Record payment</button>
        <button v-if="!['received', 'cancelled'].includes(order.status)" class="btn-secondary" @click="$emit('edit', order)"><Pencil class="size-4" />Edit</button>
        <button v-if="order.units_received === 0 && !order.payments.length && order.status !== 'cancelled'" class="btn-danger" @click="cancelOrder">Cancel order</button>
        <button v-if="order.status === 'draft' && !order.payments.length" class="btn-danger" @click="remove"><Trash2 class="size-4" />Delete</button>
      </div>

      <section>
        <h3 class="text-sm font-semibold">Products</h3>
        <table class="mt-2 w-full text-sm">
          <thead class="text-left text-xs text-muted-foreground">
            <tr><th class="py-2 font-medium">Product</th><th class="py-2 text-right font-medium">Received</th><th class="py-2 text-right font-medium">Unit cost</th><th class="py-2 text-right font-medium">Landed</th><th class="py-2 text-right font-medium">Total</th></tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="i in order.items" :key="i.id">
              <td class="py-2"><span class="font-mono text-xs font-semibold">{{ i.sku }}</span><p class="text-xs text-muted-foreground">{{ i.name }}</p></td>
              <td class="py-2 text-right tabular-nums" :class="i.quantity_received >= i.quantity ? 'text-emerald-600' : ''">{{ i.quantity_received }} / {{ i.quantity }}</td>
              <td class="py-2 text-right tabular-nums">{{ moneyExact(i.unit_cost) }}</td>
              <td class="py-2 text-right tabular-nums text-muted-foreground" title="Unit cost plus its share of shipping and other costs">{{ moneyExact(landed(i)) }}</td>
              <td class="py-2 text-right tabular-nums">{{ moneyExact(i.quantity * i.unit_cost) }}</td>
            </tr>
          </tbody>
        </table>
        <dl class="mt-3 ml-auto max-w-xs space-y-1 text-sm">
          <div class="flex justify-between"><dt class="text-muted-foreground">Products</dt><dd class="tabular-nums">{{ moneyExact(order.subtotal) }}</dd></div>
          <div v-if="order.shipping_cost" class="flex justify-between"><dt class="text-muted-foreground">Shipping</dt><dd class="tabular-nums">{{ moneyExact(order.shipping_cost) }}</dd></div>
          <div v-if="order.other_costs" class="flex justify-between"><dt class="text-muted-foreground">Other costs</dt><dd class="tabular-nums">{{ moneyExact(order.other_costs) }}</dd></div>
          <div class="flex justify-between border-t border-border pt-1 font-semibold"><dt>Total</dt><dd class="tabular-nums">{{ moneyExact(order.total) }}</dd></div>
          <div class="flex justify-between"><dt class="text-muted-foreground">Paid</dt><dd class="tabular-nums">{{ moneyExact(order.amount_paid) }}</dd></div>
          <div class="flex justify-between font-semibold" :class="order.balance_due > 0 ? 'text-rose-600' : ''"><dt>Still owed</dt><dd class="tabular-nums">{{ moneyExact(order.balance_due) }}</dd></div>
        </dl>
      </section>

      <section v-if="order.receipts.length">
        <h3 class="text-sm font-semibold">Deliveries</h3>
        <ul class="mt-2 divide-y divide-border text-sm">
          <li v-for="r in order.receipts" :key="r.id" class="flex justify-between gap-3 py-2">
            <span>{{ date(`${r.received_on}T12:00:00`) }}<span class="block text-xs text-muted-foreground">{{ r.lines.map((l) => `${l.quantity} × ${l.item?.sku}`).join(', ') }}{{ r.note ? ` · ${r.note}` : '' }}</span></span>
            <span class="tabular-nums">{{ moneyExact(r.value) }}</span>
          </li>
        </ul>
      </section>

      <section v-if="order.payments.length">
        <h3 class="text-sm font-semibold">Payments</h3>
        <ul class="mt-2 divide-y divide-border text-sm">
          <li v-for="p in order.payments" :key="p.id" class="flex items-center justify-between gap-3 py-2">
            <span>{{ date(`${p.paid_on}T12:00:00`) }}<span class="block text-xs text-muted-foreground">{{ [p.method, p.reference].filter(Boolean).join(' · ') || '—' }}</span></span>
            <span class="flex items-center gap-2 tabular-nums">{{ moneyExact(p.amount) }}
              <button :aria-label="`Delete payment of ${p.amount}`" class="rounded-md p-1 text-muted-foreground hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/40" @click="removePayment(p)"><Trash2 class="size-3.5" /></button>
            </span>
          </li>
        </ul>
      </section>

      <p v-if="order.notes" class="whitespace-pre-line rounded-lg bg-muted/50 p-3 text-sm text-muted-foreground">{{ order.notes }}</p>
    </div>

    <Modal :open="receive.open" title="Receive stock" :description="`What arrived from ${order?.supplier?.name}? Stock and product costs update straight away.`" @close="receive.open = false">
      <form class="space-y-4" @submit.prevent="submitReceive">
        <table class="w-full text-sm">
          <thead class="text-left text-xs text-muted-foreground"><tr><th class="py-1.5 font-medium">Product</th><th class="py-1.5 text-right font-medium">Still expected</th><th class="w-28 py-1.5 font-medium">Arrived now</th></tr></thead>
          <tbody>
            <tr v-for="i in openLines" :key="i.id">
              <td class="py-1"><span class="font-mono text-xs font-semibold">{{ i.sku }}</span></td>
              <td class="py-1 text-right tabular-nums">{{ i.quantity - i.quantity_received }}</td>
              <td class="py-1 pl-3"><input v-model.number="receive.items[i.id]" type="number" min="0" :max="i.quantity - i.quantity_received" class="input" :aria-label="`Units of ${i.sku} received`" /></td>
            </tr>
          </tbody>
        </table>
        <p v-for="(message, key) in receive.errors" :key="key" class="field-error">{{ message }}</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="label">Received on<input v-model="receive.received_on" type="date" class="input mt-2" /></label>
          <label class="label">Note <span class="font-normal text-muted-foreground">(optional)</span><input v-model="receive.note" class="input mt-2" placeholder="e.g. 2 boxes damaged" /></label>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="receive.open = false">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy">Add to stock</button>
        </div>
      </form>
    </Modal>

    <Modal :open="payment.open" title="Record supplier payment" width="max-w-md" @close="payment.open = false">
      <form class="space-y-4" @submit.prevent="submitPayment">
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="label">Amount<input v-model.number="payment.amount" type="number" min="0.01" step="0.01" class="input mt-2" /></label>
          <label class="label">Paid on<input v-model="payment.paid_on" type="date" class="input mt-2" /></label>
          <label class="label">Method<input v-model="payment.method" list="pay-methods" class="input mt-2" placeholder="Bank transfer" /></label>
          <label class="label">Reference<input v-model="payment.reference" class="input mt-2" placeholder="Invoice or transfer no." /></label>
        </div>
        <datalist id="pay-methods"><option value="Bank transfer" /><option value="Card" /><option value="Cash" /><option value="PayPal" /><option value="Wise" /></datalist>
        <p v-if="payment.errors.amount" class="field-error">{{ payment.errors.amount }}</p>
        <p class="text-xs text-muted-foreground">{{ moneyExact(order?.balance_due ?? 0) }} still owed. Recorded as cash paid to the supplier.</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" @click="payment.open = false">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !payment.amount">Record payment</button>
        </div>
      </form>
    </Modal>
  </Drawer>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { Banknote, PackageCheck, Pencil, Send, Trash2, X } from 'lucide-vue-next';
import Drawer from '@/components/Drawer.vue';
import Modal from '@/components/Modal.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import api, { errorMessage, fieldErrors } from '@/lib/api';
import { date, moneyExact } from '@/lib/format';
import { toast } from '@/lib/toast';

const props = defineProps({ order: Object });
const emit = defineEmits(['close', 'changed', 'edit', 'deleted']);

const busy = ref(false);
const today = () => new Date().toLocaleDateString('en-CA');
const receive = reactive({ open: false, items: {}, received_on: '', note: '', errors: {} });
const payment = reactive({ open: false, amount: null, paid_on: '', method: '', reference: '', errors: {} });

const canReceive = computed(() => ['draft', 'ordered', 'partially_received'].includes(props.order?.status));
const openLines = computed(() => (props.order?.items ?? []).filter((i) => i.quantity_received < i.quantity));
const overdue = computed(() => ['ordered', 'partially_received'].includes(props.order?.status) && props.order?.expected_on && props.order.expected_on < today());

/** Unit cost plus the line's share (by value) of shipping and other costs. */
function landed(item) {
  const o = props.order;
  const extra = (o.shipping_cost || 0) + (o.other_costs || 0);
  if (!extra || !o.subtotal || !item.quantity) return item.unit_cost;
  return item.unit_cost + (extra * ((item.quantity * item.unit_cost) / o.subtotal)) / item.quantity;
}

async function run(request, message) {
  busy.value = true;
  try {
    const { data } = await request();
    if (message) toast(message);
    emit('changed', data);
    return true;
  } catch (error) {
    toast(Object.values(fieldErrors(error))[0] ?? errorMessage(error), 'error');
    return false;
  } finally {
    busy.value = false;
  }
}

const act = (action, body, message) => run(() => api.post(`/purchasing/orders/${props.order.id}/${action}`, body), message);

function cancelOrder() {
  if (confirm(`Cancel ${props.order.number}?`)) act('cancel', {}, 'Purchase order cancelled');
}

async function remove() {
  if (!confirm(`Delete draft ${props.order.number}?`)) return;
  try {
    await api.delete(`/purchasing/orders/${props.order.id}`);
    toast('Draft deleted');
    emit('deleted');
  } catch (error) {
    toast(Object.values(fieldErrors(error))[0] ?? errorMessage(error), 'error');
  }
}

function openReceive() {
  Object.assign(receive, { open: true, received_on: today(), note: '', errors: {}, items: Object.fromEntries(openLines.value.map((i) => [i.id, i.quantity - i.quantity_received])) });
}

async function submitReceive() {
  busy.value = true;
  receive.errors = {};
  try {
    const { data } = await api.post(`/purchasing/orders/${props.order.id}/receive`, { items: receive.items, received_on: receive.received_on, note: receive.note || null });
    receive.open = false;
    toast(data.status === 'received' ? 'All stock received' : 'Delivery recorded');
    emit('changed', data);
  } catch (error) {
    receive.errors = fieldErrors(error);
    if (!Object.keys(receive.errors).length) toast(errorMessage(error), 'error');
  } finally {
    busy.value = false;
  }
}

function openPayment() {
  Object.assign(payment, { open: true, amount: props.order.balance_due, paid_on: today(), method: '', reference: '', errors: {} });
}

async function submitPayment() {
  busy.value = true;
  payment.errors = {};
  try {
    const { amount, paid_on, method, reference } = payment;
    const { data } = await api.post(`/purchasing/orders/${props.order.id}/payments`, { amount, paid_on, method: method || null, reference: reference || null });
    payment.open = false;
    toast('Payment recorded');
    emit('changed', data);
  } catch (error) {
    payment.errors = fieldErrors(error);
    if (!Object.keys(payment.errors).length) toast(errorMessage(error), 'error');
  } finally {
    busy.value = false;
  }
}

function removePayment(p) {
  if (confirm(`Delete the payment of ${moneyExact(p.amount)}?`)) run(() => api.delete(`/purchasing/orders/${props.order.id}/payments/${p.id}`), 'Payment deleted');
}
</script>
