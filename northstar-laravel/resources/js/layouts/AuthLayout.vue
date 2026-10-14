<template>
  <div class="grid min-h-screen bg-[#f7f7fb] lg:grid-cols-[1fr_1.1fr] dark:bg-[#111216]">
    <section class="relative hidden overflow-hidden bg-indigo-600 p-12 text-white lg:flex lg:flex-col">
      <div class="flex items-center gap-2.5">
        <div class="flex size-9 items-center justify-center rounded-xl bg-white text-sm font-bold text-indigo-600">N</div>
        <span class="text-xl font-bold tracking-tight">northstar</span>
      </div>
      <div class="my-auto max-w-md">
        <h1 class="text-4xl font-semibold leading-tight tracking-tight">Every channel. One source of truth.</h1>
        <p class="mt-4 text-indigo-100">Manage inventory, orders, and profitability across Shopify, Amazon, Etsy, and eBay from a single dashboard.</p>
        <ul class="mt-8 space-y-3 text-sm text-indigo-50">
          <li v-for="point in points" :key="point" class="flex items-center gap-3">
            <span class="flex size-5 items-center justify-center rounded-full bg-white/15"><Check class="size-3" /></span>{{ point }}
          </li>
        </ul>
      </div>
      <p class="text-xs text-indigo-200">© {{ new Date().getFullYear() }} Northstar Commerce</p>
      <div class="pointer-events-none absolute -bottom-32 -right-32 size-96 rounded-full bg-white/10" />
      <div class="pointer-events-none absolute -top-24 right-24 size-56 rounded-full bg-white/5" />
    </section>

    <section class="flex items-center justify-center px-4 py-12 sm:px-8">
      <div class="w-full max-w-md">
        <div class="mb-8 flex items-center gap-2.5 lg:hidden">
          <div class="flex size-8 items-center justify-center rounded-xl bg-indigo-600 text-sm font-bold text-white">N</div>
          <span class="text-lg font-bold tracking-tight">northstar</span>
        </div>
        <RouterView />
      </div>
    </section>
  </div>
</template>

<script setup>
import { Check } from 'lucide-vue-next';

const points = ['Unified orders from every marketplace', 'Real-time stock across warehouses', 'Profit & loss and balance sheet, always current'];
</script>
