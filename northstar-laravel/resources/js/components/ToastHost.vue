<template>
  <div class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2" aria-live="polite">
    <TransitionGroup enter-from-class="translate-y-2 opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
      <div
        v-for="t in toasts"
        :key="t.id"
        class="pointer-events-auto flex items-start gap-3 rounded-xl border bg-card p-4 text-sm shadow-lg"
        :class="t.type === 'error' ? 'border-rose-200 dark:border-rose-900' : 'border-border'"
      >
        <CircleCheck v-if="t.type !== 'error'" class="size-4 shrink-0 text-emerald-600" />
        <TriangleAlert v-else class="size-4 shrink-0 text-rose-600" />
        <p>{{ t.message }}</p>
      </div>
    </TransitionGroup>
  </div>
</template>

<script setup>
import { CircleCheck, TriangleAlert } from 'lucide-vue-next';
import { toasts } from '@/lib/toast';
</script>
