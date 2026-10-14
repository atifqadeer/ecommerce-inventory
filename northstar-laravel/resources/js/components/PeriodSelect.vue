<template>
  <div class="flex flex-wrap items-center gap-2">
    <label class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm">
      <CalendarDays class="size-4 text-muted-foreground" />
      <select :value="choice" aria-label="Period" class="bg-card font-medium text-card-foreground outline-none" @change="choose($event.target.value)">
        <option v-for="p in workspace.meta?.periods ?? []" :key="p.value" :value="p.value">{{ p.label }}</option>
        <optgroup v-if="years.length" label="Year">
          <option v-for="y in years" :key="y" :value="`year:${y}`">{{ y }}</option>
        </optgroup>
        <option value="custom">Custom range…</option>
      </select>
    </label>
    <div v-if="choice === 'custom'" class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-1.5 text-sm">
      <input v-model="range.from" type="month" aria-label="From month" class="bg-card text-card-foreground outline-none" :max="range.to" @change="emitRange" />
      <span class="text-muted-foreground">to</span>
      <input v-model="range.to" type="month" aria-label="To month" class="bg-card text-card-foreground outline-none" :min="range.from" @change="emitRange" />
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, watch } from 'vue';
import { CalendarDays } from 'lucide-vue-next';
import { useWorkspaceStore } from '@/stores/workspace';

/**
 * Period picker shared by the finance pages. The value is a preset ("last_6_months"), a calendar year
 * ("year:2025") or a range of months ("custom:2025-01:2026-12").
 */
const props = defineProps({ modelValue: { type: String, required: true } });
const emit = defineEmits(['update:modelValue']);

const workspace = useWorkspaceStore();
const years = computed(() => workspace.meta?.years ?? []);
const choice = computed(() => (props.modelValue.startsWith('custom:') ? 'custom' : props.modelValue));

const thisMonth = () => new Date().toISOString().slice(0, 7);
const range = reactive({ from: `${new Date().getFullYear()}-01`, to: thisMonth() });

// Keep the month pickers in step with a custom value set from outside (e.g. the URL).
watch(() => props.modelValue, (value) => {
  const [, from, to] = value.match(/^custom:(\d{4}-\d{2}):(\d{4}-\d{2})$/) ?? [];
  if (from) Object.assign(range, { from, to });
}, { immediate: true });

function choose(value) {
  if (value === 'custom') emitRange();
  else emit('update:modelValue', value);
}

function emitRange() {
  if (!range.from || !range.to) return;
  // Accept the months in either order.
  const [from, to] = range.from <= range.to ? [range.from, range.to] : [range.to, range.from];
  emit('update:modelValue', `custom:${from}:${to}`);
}
</script>
