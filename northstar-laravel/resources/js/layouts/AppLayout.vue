<template>
  <div class="min-h-screen bg-[#f7f7fb] text-foreground dark:bg-[#111216]">
    <div v-if="mobileNav" class="fixed inset-0 z-20 bg-slate-950/30 lg:hidden" @click="mobileNav = false" />

    <aside
      class="fixed inset-y-0 left-0 z-30 flex w-64 flex-col border-r border-border bg-card px-4 py-5 transition-transform lg:translate-x-0"
      :class="mobileNav ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="mb-8 flex items-center justify-between px-2">
        <RouterLink :to="{ name: 'overview' }" class="flex items-center gap-2.5">
          <div class="flex size-8 items-center justify-center rounded-xl bg-indigo-600 text-sm font-bold text-white">N</div>
          <span class="text-lg font-bold tracking-tight">northstar</span>
        </RouterLink>
        <button aria-label="Close menu" class="lg:hidden" @click="mobileNav = false"><X class="size-5" /></button>
      </div>

      <div class="mb-3 px-3 text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Workspace</div>
      <nav class="flex flex-col gap-1">
        <RouterLink
          v-for="item in nav"
          :key="item.name"
          :to="{ name: item.name }"
          :data-tour="`nav-${item.name}`"
          class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition"
          :class="isActive(item) ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300' : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
          @click="mobileNav = false"
        >
          <component :is="item.icon" class="size-[17px]" />
          <span>{{ item.label }}</span>
          <span v-if="item.count" class="ml-auto rounded-full px-2 py-0.5 text-[10px]" :class="item.alert ? 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300' : 'bg-muted'">{{ item.count }}</span>
        </RouterLink>
      </nav>

      <div class="mt-auto flex flex-col gap-1">
        <RouterLink
          :to="{ name: 'settings' }"
          class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm"
          :class="route.name === 'settings' ? 'bg-indigo-50 font-medium text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300' : 'text-muted-foreground hover:bg-muted'"
          @click="mobileNav = false"
        >
          <Settings class="size-[17px]" />Settings
        </RouterLink>
        <button class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-muted-foreground hover:bg-muted" @click="logout"><LogOut class="size-[17px]" />Sign out</button>
        <div class="mt-3 flex items-center gap-3 border-t border-border px-3 pt-4">
          <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-semibold text-amber-800">{{ initials(auth.user?.name) }}</div>
          <div class="min-w-0">
            <p class="truncate text-xs font-semibold">{{ auth.user?.name }}</p>
            <p class="truncate text-[11px] text-muted-foreground">{{ auth.user?.email }}</p>
          </div>
        </div>
      </div>
    </aside>

    <div class="lg:pl-64">
      <header class="sticky top-0 z-10 flex h-16 items-center justify-between border-b border-border bg-card/80 px-5 backdrop-blur md:px-8">
        <button aria-label="Open menu" data-tour="menu" class="rounded-lg p-1 lg:hidden" @click="mobileNav = true"><Menu class="size-5" /></button>
        <div class="hidden text-sm text-muted-foreground lg:block">
          {{ auth.user?.company || 'Workspace' }} / <span class="font-medium text-foreground">{{ route.meta.title }}</span>
        </div>
        <div class="ml-auto flex items-center gap-1">
          <button aria-label="Take the product tour" title="Take the product tour" data-tour="help" class="rounded-lg p-2 text-muted-foreground hover:bg-muted" @click="replayTour">
            <CircleHelp class="size-4" />
          </button>
          <button aria-label="Toggle dark mode" class="rounded-lg p-2 text-muted-foreground hover:bg-muted" @click="toggleTheme">
            <Moon v-if="!isDark" class="size-4" /><Sun v-else class="size-4" />
          </button>
          <RouterLink :to="{ name: 'channels' }" aria-label="Notifications" class="relative rounded-lg p-2 text-muted-foreground hover:bg-muted">
            <Bell class="size-4" />
            <span v-if="workspace.counts.channel_issues" class="absolute right-1.5 top-1.5 size-2 rounded-full bg-rose-500" />
          </RouterLink>
          <RouterLink :to="{ name: 'settings' }" class="ml-2 flex items-center gap-2 border-l border-border pl-3">
            <div class="flex size-8 items-center justify-center rounded-full bg-amber-100 text-xs font-semibold text-amber-800">{{ initials(auth.user?.name) }}</div>
            <span class="hidden text-sm font-medium md:block">{{ auth.user?.name }}</span>
          </RouterLink>
        </div>
      </header>

      <main class="mx-auto max-w-[1440px] p-5 md:p-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeftRight, Bell, BookOpen, CircleHelp, ClipboardList, Download, Landmark, LayoutDashboard, LogOut, Menu, Moon, Package, Settings, ShoppingBag, SlidersHorizontal, Store, Sun, WalletCards, X } from 'lucide-vue-next';
import { useAuthStore } from '@/stores/auth';
import { useWorkspaceStore } from '@/stores/workspace';
import { initials } from '@/lib/format';
import { isDark, toggleTheme } from '@/lib/theme';
import { tourRequested } from '@/lib/tour';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const workspace = useWorkspaceStore();
const mobileNav = ref(false);

const nav = computed(() => [
  { name: 'overview', label: 'Overview', icon: LayoutDashboard },
  { name: 'orders', label: 'Orders', icon: ShoppingBag, count: workspace.counts.open_orders || null, match: ['order'] },
  { name: 'inventory', label: 'Inventory', icon: Package, count: workspace.counts.low_stock || null, alert: true },
  { name: 'products', label: 'Products', icon: Store },
  { name: 'purchasing', label: 'Purchasing', icon: ClipboardList },
  { name: 'channels', label: 'Channels', icon: SlidersHorizontal, count: workspace.counts.channel_issues || null, alert: true },
  { name: 'finance', label: 'Finance', icon: WalletCards },
  { name: 'cash-flow', label: 'Cash Flow', icon: ArrowLeftRight },
  { name: 'payments', label: 'Payments', icon: Landmark },
  { name: 'balance-sheet', label: 'Balance Sheet', icon: BookOpen },
  { name: 'reports', label: 'Reports', icon: Download },
]);

const isActive = (item) => route.name === item.name || item.match?.includes(route.name);

/** The tour walks through the Overview page, so go there and let it start the tour. */
function replayTour() {
  mobileNav.value = false;
  tourRequested.value = true;
  router.push({ name: 'overview' });
}

async function logout() {
  await auth.logout();
  workspace.reset();
}

onMounted(() => workspace.refresh());
</script>
