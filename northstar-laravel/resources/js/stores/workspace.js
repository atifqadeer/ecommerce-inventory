import { defineStore } from 'pinia';
import api from '@/lib/api';
import { setCurrency } from '@/lib/format';

/** Shared lookups (channels, option lists) and sidebar badge counts. */
export const useWorkspaceStore = defineStore('workspace', {
  state: () => ({
    meta: null,
    counts: { open_orders: 0, low_stock: 0, channel_issues: 0 },
  }),

  getters: {
    channels: (state) => state.meta?.channels ?? [],
  },

  actions: {
    async loadMeta() {
      const { data } = await api.get('/meta');
      setCurrency(data.currency);
      this.meta = data;
    },

    async refreshCounts() {
      const { data } = await api.get('/counts');
      this.counts = data;
    },

    /** Reload after anything that changes channels, orders or stock. */
    async refresh() {
      await Promise.all([this.loadMeta(), this.refreshCounts()]);
    },

    reset() {
      setCurrency();
      this.meta = null;
      this.counts = { open_orders: 0, low_stock: 0, channel_issues: 0 };
    },
  },
});
