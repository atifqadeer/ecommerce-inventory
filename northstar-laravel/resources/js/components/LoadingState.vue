<template>
  <div class="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground">
    <LoaderCircle class="size-4 animate-spin" />{{ label }}
  </div>
</template>

<script setup>
import { LoaderCircle } from 'lucide-vue-next';

defineProps({ label: { type: String, default: 'Loading…' } });
</script>
