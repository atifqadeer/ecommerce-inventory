<template>
  <div class="space-y-6">
    <RouterLink :to="{ name: 'orders' }" class="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" />Back to orders</RouterLink>

    <LoadingState v-if="!order" />

    <template v-else>
      <div class="flex flex-col justify-between gap-3 md:flex-row md:items-end">
        <div>
          <p class="font-mono text-xs text-indigo-600 dark:text-indigo-400">{{ order.number }}</p>
          <h1 class="page-title">Order details</h1>
          <p class="mt-1 text-sm text-muted-foreground">Placed {{ dateTime(order.placed_at) }} by {{ order.customer_name }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <ChannelBadge :name="order.channel?.name" :platform="order.channel?.platform" />
          <StatusBadge :status="order.status" />
          <button v-for="next in nextStatuses" :key="next" :class="next === 'cancelled' ? 'btn-danger' : 'btn-primary'" :disabled="busy" @click="transition(next)">
            {{ actionLabels[next] }}
          </button>
          <button v-if="order.status !== 'cancelled' && refundable > 0" class="btn-secondary" :disabled="busy" @click="showRefund = true">Issue refund</button>
        </div>
      </div>

      <div class="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
        <div class="space-y-6">
          <section class="card p-5">
            <h2 class="font-semibold">Line items</h2>
            <div class="mt-4 divide-y divide-border">
              <div v-for="item in order.items" :key="item.id" class="flex items-center justify-between py-3">
                <div>
                  <p class="font-medium">{{ item.name }}</p>
                  <p class="mt-1 font-mono text-xs text-muted-foreground">{{ item.sku }} · Qty {{ item.quantity }} × {{ moneyExact(item.unit_price) }}</p>
                </div>
                <span class="font-medium">{{ moneyExact(item.quantity * item.unit_price) }}</span>
              </div>
            </div>
          </section>

          <section class="card p-5">
            <h2 class="font-semibold">Status timeline</h2>
            <div class="mt-5 border-l border-border pl-5">
              <div v-for="(event, i) in order.events" :key="event.id" class="relative" :class="{ 'pb-6': i < order.events.length - 1 || pending }">
                <span class="absolute -left-[25px] top-1 size-2 rounded-full" :class="event.label.startsWith('Order cancelled') || event.label.startsWith('Refund') ? 'bg-rose-500' : 'bg-indigo-600'" />
                <p class="text-sm font-medium">{{ event.label }}</p>
                <p class="text-xs text-muted-foreground">{{ dateTime(event.created_at) }}</p>
              </div>
              <div v-if="pending" class="relative">
                <span class="absolute -left-[25px] top-1 size-2 rounded-full bg-slate-300" />
                <p class="text-sm font-medium text-muted-foreground">{{ pending }}</p>
                <p class="text-xs text-muted-foreground">Next step</p>
              </div>
            </div>
          </section>
        </div>

        <div class="space-y-6">
          <section class="card p-5">
            <h2 class="font-semibold">Financial breakdown</h2>
            <div class="mt-4 space-y-3 text-sm">
              <div v-for="[label, value] in breakdown" :key="label" class="flex justify-between">
                <span class="text-muted-foreground">{{ label }}</span><span>{{ value }}</span>
              </div>
              <div class="flex justify-between border-t border-border pt-3 text-base font-semibold">
                <span>Net profit</span>
                <span :class="order.net_profit >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ moneyExact(order.net_profit) }}</span>
              </div>
            </div>
          </section>

          <section class="card p-5">
            <h2 class="font-semibold">Customer & shipping</h2>
            <div class="mt-4 space-y-4 text-sm">
              <div>
                <p class="text-xs text-muted-foreground">Customer</p>
                <p class="mt-1 font-medium">{{ order.customer_name }}</p>
                <p v-if="order.customer_email" class="text-muted-foreground">{{ order.customer_email }}</p>
              </div>
              <div v-if="order.shipping_address">
                <p class="text-xs text-muted-foreground">Ship to</p>
                <p class="mt-1 whitespace-pre-line">{{ order.shipping_address }}</p>
              </div>
              <div>
                <p class="text-xs text-muted-foreground">Fulfillment</p>
                <p class="mt-1 font-medium">{{ order.fulfillment === 'FBA' ? 'Amazon FBA' : 'Northstar warehouse · Ground' }}</p>
              </div>
            </div>
          </section>
        </div>
      </div>

      <Modal :open="showRefund" title="Issue refund" :description="`Up to ${moneyExact(refundable)} can be refunded.`" width="max-w-sm" @close="showRefund = false">
        <form class="space-y-4" @submit.prevent="refund">
          <label class="label">Amount
            <input v-model.number="refundAmount" type="number" min="0.01" step="0.01" :max="refundable" class="input mt-2" />
            <p v-if="refundError" class="field-error">{{ refundError }}</p>
          </label>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn-secondary" @click="showRefund = false">Cancel</button>
            <button type="submit" class="btn-primary" :disabled="busy || !refundAmount">Refund</button>
          </div>
        </form>
      </Modal>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { ArrowLeft } from 'lucide-vue-next';
import ChannelBadge from '@/components/ChannelBadge.vue';
import LoadingState from '@/components/LoadingState.vue';
import Modal from '@/components/Modal.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import api, { errorMessage, fieldErrors } from '@/lib/api';
import { dateTime, moneyExact } from '@/lib/format';
import { toast } from '@/lib/toast';
import { useWorkspaceStore } from '@/stores/workspace';

const props = defineProps({ id: String });
const workspace = useWorkspaceStore();

const order = ref(null);
const busy = ref(false);
const showRefund = ref(false);
const refundAmount = ref(null);
const refundError = ref('');

const actionLabels = { processing: 'Start processing', shipped: 'Mark shipped', delivered: 'Mark delivered', cancelled: 'Cancel order' };
const nextLabels = { paid: 'Awaiting fulfillment', processing: 'Awaiting shipment', shipped: 'Awaiting delivery' };

const nextStatuses = computed(() => workspace.meta?.order_transitions?.[order.value.status] ?? []);
const pending = computed(() => nextLabels[order.value.status]);
const refundable = computed(() => Math.round((order.value.subtotal - order.value.refunds) * 100) / 100);
const breakdown = computed(() => [
  ['Gross sale', moneyExact(order.value.subtotal)],
  ['Channel fees', moneyExact(-order.value.channel_fees)],
  ['Shipping', moneyExact(-order.value.shipping_cost)],
  ['COGS', moneyExact(-order.value.cogs)],
  ['Refunds', moneyExact(-order.value.refunds)],
]);

async function transition(status) {
  if (status === 'cancelled' && !confirm('Cancel this order? Reserved stock will be released.')) return;
  busy.value = true;
  try {
    order.value = (await api.patch(`/orders/${props.id}/status`, { status })).data;
    toast(`Order marked ${status}`);
    workspace.refreshCounts();
  } catch (error) {
    toast(errorMessage(error), 'error');
  } finally {
    busy.value = false;
  }
}

async function refund() {
  busy.value = true;
  refundError.value = '';
  try {
    order.value = (await api.post(`/orders/${props.id}/refund`, { amount: refundAmount.value })).data;
    showRefund.value = false;
    refundAmount.value = null;
    toast('Refund recorded');
  } catch (error) {
    refundError.value = fieldErrors(error).amount ?? errorMessage(error);
  } finally {
    busy.value = false;
  }
}

onMounted(async () => {
  order.value = (await api.get(`/orders/${props.id}`)).data;
});
</script>
