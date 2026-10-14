<template>
  <div class="space-y-6">
    <div>
      <p class="eyebrow">{{ greeting }}, {{ auth.firstName }}</p>
      <h1 class="page-title">Here's your business at a glance.</h1>
      <p class="mt-1 text-sm text-muted-foreground">
        {{ today }} · Last 30 days ·
        <span v-if="!data || data.all_synced">All channels synced</span>
        <RouterLink v-else :to="{ name: 'channels' }" class="font-medium text-rose-600 hover:underline">A channel needs attention</RouterLink>
      </p>
    </div>

    <LoadingState v-if="!data" />

    <template v-else>
      <div data-tour="stats" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <section v-for="s in data.stats" :key="s.key" class="card p-5">
          <p class="text-sm text-muted-foreground">{{ s.label }}</p>
          <div class="mt-3 flex items-end justify-between">
            <p class="text-2xl font-semibold">{{ s.format === 'money' ? money(s.value) : number(s.value) }}</p>
            <span v-if="s.change !== null" class="text-xs font-semibold" :class="s.change < 0 ? 'text-rose-600' : 'text-emerald-600'">{{ percent(s.change) }}</span>
            <span v-else class="text-xs text-muted-foreground">{{ s.key === 'inventory_value' ? 'at cost' : s.value > 0 ? 'new' : '—' }}</span>
          </div>
          <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-muted" :title="s.previous === null ? undefined : `Previous 30 days: ${s.format === 'money' ? money(s.previous) : number(s.previous)}`">
            <div class="h-full rounded-full bg-indigo-500 transition-[width]" :style="{ width: `${barWidth(s)}%` }" />
          </div>
        </section>
      </div>

      <div class="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
        <section data-tour="recent-orders" class="card p-5">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="font-semibold">Recent orders</h2>
              <p class="mt-1 text-xs text-muted-foreground">Latest activity across connected channels</p>
            </div>
            <RouterLink :to="{ name: 'orders' }" class="text-xs font-medium text-indigo-600 dark:text-indigo-400">View all</RouterLink>
          </div>
          <div class="mt-4 divide-y divide-border">
            <RouterLink v-for="o in data.recent_orders" :key="o.id" :to="{ name: 'order', params: { id: o.id } }" class="-mx-2 flex items-center justify-between rounded-lg px-2 py-3 hover:bg-muted/40">
              <div>
                <p class="font-mono text-xs font-semibold">{{ o.number }}</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ o.customer_name }} · {{ relativeDay(o.placed_at) }}</p>
              </div>
              <div class="text-right">
                <p class="text-sm font-semibold">{{ moneyExact(o.subtotal) }}</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ o.channel?.name }}</p>
              </div>
            </RouterLink>
            <p v-if="!data.recent_orders.length" class="py-8 text-center text-sm text-muted-foreground">No orders yet.</p>
          </div>
        </section>

        <section data-tour="channel-performance" class="card p-5">
          <h2 class="font-semibold">Channel performance</h2>
          <p class="mt-1 text-xs text-muted-foreground">Revenue by channel, last 30 days</p>
          <div class="mt-6 space-y-4">
            <div v-for="c in data.channels" :key="c.id">
              <div class="mb-1 flex justify-between text-sm">
                <span>{{ c.name }}</span><span class="font-semibold">{{ money(c.revenue) }}</span>
              </div>
              <div class="h-2 rounded-full bg-muted">
                <div class="h-full rounded-full" :style="{ width: `${c.share}%`, background: c.color }" />
              </div>
            </div>
            <p v-if="!data.channels.length" class="py-6 text-center text-sm text-muted-foreground">
              No channels yet. <RouterLink :to="{ name: 'channels' }" class="font-medium text-indigo-600">Connect one</RouterLink>
            </p>
          </div>
        </section>
      </div>

      <div class="grid gap-6 lg:grid-cols-2">
        <section class="card p-5">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="font-semibold">Low stock</h2>
              <p class="mt-1 text-xs text-muted-foreground">Available below reorder point</p>
            </div>
            <RouterLink :to="{ name: 'inventory', query: { low_stock: 1 } }" class="text-xs font-medium text-indigo-600 dark:text-indigo-400">Manage stock</RouterLink>
          </div>
          <div class="mt-4 divide-y divide-border">
            <div v-for="p in data.low_stock" :key="p.id" class="flex items-center justify-between py-3">
              <div>
                <p class="text-sm font-medium">{{ p.name }}</p>
                <p class="mt-1 font-mono text-xs text-muted-foreground">{{ p.sku }}</p>
              </div>
              <p class="text-sm"><span class="font-semibold text-rose-600">{{ p.available }}</span> <span class="text-muted-foreground">/ {{ p.reorder_point }}</span></p>
            </div>
            <p v-if="!data.low_stock.length" class="py-8 text-center text-sm text-muted-foreground">Everything is well stocked.</p>
          </div>
        </section>

        <section class="card p-5">
          <h2 class="font-semibold">Top products</h2>
          <p class="mt-1 text-xs text-muted-foreground">Best sellers by units, last 30 days</p>
          <table class="mt-4 w-full text-sm">
            <thead class="text-left text-[11px] uppercase tracking-wide text-muted-foreground">
              <tr><th class="pb-2 font-medium">Product</th><th class="pb-2 text-right font-medium">Units</th><th class="pb-2 text-right font-medium">Revenue</th><th class="pb-2 text-right font-medium">Profit</th></tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="p in data.top_products" :key="p.sku">
                <td class="py-2.5"><p class="font-medium">{{ p.name }}</p><p class="font-mono text-xs text-muted-foreground">{{ p.sku }}</p></td>
                <td class="py-2.5 text-right tabular-nums">{{ number(p.units) }}</td>
                <td class="py-2.5 text-right tabular-nums">{{ money(p.revenue) }}</td>
                <td class="py-2.5 text-right tabular-nums text-emerald-600">{{ money(p.profit) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="!data.top_products.length" class="py-8 text-center text-sm text-muted-foreground">No sales in the last 30 days.</p>
        </section>
      </div>
    </template>
  </div>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import LoadingState from '@/components/LoadingState.vue';
import api from '@/lib/api';
import { money, moneyExact, number, percent, relativeDay } from '@/lib/format';
import { startTour, stopTour, tourRequested } from '@/lib/tour';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const data = ref(null);

const hour = new Date().getHours();
const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
const today = new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

/** This period against the larger of this and the previous period: full when flat or growing, empty when there's nothing (or a loss). */
function barWidth(stat) {
  const value = Math.max(0, stat.value);
  const scale = Math.max(value, stat.previous ?? 0);
  return scale > 0 ? Math.round((value / scale) * 100) : 0;
}

/** Auto-start the tour on a user's first visit, or when they asked to replay it. */
async function maybeStartTour() {
  if (!data.value || (auth.user?.tour_completed_at && !tourRequested.value)) return;
  tourRequested.value = false;
  await nextTick();
  startTour({
    firstName: auth.firstName,
    hasChannels: data.value.channels.length > 0,
    onFinish: () => auth.completeTour(),
  });
}

// Replay requested while already on this page.
watch(tourRequested, (requested) => requested && maybeStartTour());

onMounted(async () => {
  data.value = (await api.get('/dashboard')).data;
  maybeStartTour();
});

onBeforeUnmount(stopTour);
</script>
