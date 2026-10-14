<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/30 p-4" @click.self="$emit('close')">
      <div role="dialog" aria-modal="true" :aria-label="title" class="max-h-[90vh] w-full overflow-y-auto rounded-2xl border border-border bg-card p-6 shadow-xl" :class="width">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-semibold">{{ title }}</h2>
            <p v-if="description" class="mt-1 text-sm text-muted-foreground">{{ description }}</p>
          </div>
          <button aria-label="Close" class="rounded-md p-1 text-muted-foreground hover:bg-muted" @click="$emit('close')"><X class="size-5" /></button>
        </div>
        <div class="mt-5"><slot /></div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { onBeforeUnmount, watch } from 'vue';
import { X } from 'lucide-vue-next';

const props = defineProps({ open: Boolean, title: String, description: String, width: { type: String, default: 'max-w-lg' } });
const emit = defineEmits(['close']);

const onKey = (e) => e.key === 'Escape' && emit('close');
watch(() => props.open, (open) => (open ? window.addEventListener('keydown', onKey) : window.removeEventListener('keydown', onKey)), { immediate: true });
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>
