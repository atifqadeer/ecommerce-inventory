<template>
  <select :value="modelValue" aria-label="Channel" class="input w-auto" @change="$emit('update:modelValue', $event.target.value)">
    <option value="">All channels</option>
    <optgroup v-for="g in groups" :key="g.platform" :label="g.platform">
      <option v-if="g.stores.length > 1" :value="`platform:${g.platform}`">All {{ g.platform }} stores</option>
      <option v-for="c in g.stores" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
    </optgroup>
  </select>
</template>

<script setup>
import { computed } from 'vue';
import { useWorkspaceStore } from '@/stores/workspace';

/**
 * Store filter grouped by platform. The value is "" (all), a store id, or "platform:Shopify" for every store on
 * a platform; channelParams() turns it into API query parameters.
 */
defineProps({ modelValue: { type: String, default: '' } });
defineEmits(['update:modelValue']);

const workspace = useWorkspaceStore();

const groups = computed(() => (workspace.meta?.supported_channels ?? [])
  .map((s) => ({ platform: s.name, stores: workspace.channels.filter((c) => c.platform === s.name) }))
  .filter((g) => g.stores.length));
</script>

<script>
/** { channel_id } or { platform } for the API, from a ChannelFilter value. */
export function channelParams(value) {
  if (!value) return {};
  return value.startsWith('platform:') ? { platform: value.slice(9) } : { channel_id: value };
}
</script>
