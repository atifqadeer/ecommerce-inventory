<template>
  <span class="whitespace-nowrap rounded-full px-2 py-1 text-[11px] font-medium" :class="styles[status] ?? 'bg-muted text-muted-foreground'">
    {{ capitalize((status ?? '').replace(/_/g, ' ')) }}
  </span>
</template>

<script setup>
import { capitalize } from '@/lib/format';

defineProps({ status: String });

const styles = {
  paid: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
  processing: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
  shipped: 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
  delivered: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300',
  cancelled: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
  success: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
  failed: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
  // Purchase orders and supplier payments.
  draft: 'bg-muted text-muted-foreground',
  ordered: 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
  partially_received: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
  received: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
  unpaid: 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
  partially_paid: 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
};
</script>
