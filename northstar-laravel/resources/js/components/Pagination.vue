<template>
  <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-border px-5 py-3 text-sm">
    <span class="text-muted-foreground">Page {{ meta.current_page }} of {{ meta.last_page }} · {{ meta.total }} results</span>
    <div class="flex gap-2">
      <button class="btn-secondary px-2.5 py-1.5" :disabled="meta.current_page <= 1" aria-label="Previous page" @click="$emit('change', meta.current_page - 1)"><ChevronLeft class="size-4" /></button>
      <button class="btn-secondary px-2.5 py-1.5" :disabled="meta.current_page >= meta.last_page" aria-label="Next page" @click="$emit('change', meta.current_page + 1)"><ChevronRight class="size-4" /></button>
    </div>
  </div>
</template>

<script setup>
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

defineProps({ meta: Object });
defineEmits(['change']);
</script>
