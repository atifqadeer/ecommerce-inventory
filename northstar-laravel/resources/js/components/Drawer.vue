<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-40 bg-slate-950/25" @click.self="$emit('close')">
      <aside role="dialog" :aria-label="label" class="absolute inset-y-0 right-0 flex w-full flex-col overflow-y-auto border-l border-border bg-card shadow-2xl" :class="width">
        <slot />
      </aside>
    </div>
  </Teleport>
</template>

<script setup>
import { onBeforeUnmount, watch } from 'vue';

const props = defineProps({ open: Boolean, label: String, width: { type: String, default: 'max-w-lg' } });
const emit = defineEmits(['close']);

const onKey = (e) => e.key === 'Escape' && emit('close');
watch(() => props.open, (open) => (open ? window.addEventListener('keydown', onKey) : window.removeEventListener('keydown', onKey)), { immediate: true });
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>
