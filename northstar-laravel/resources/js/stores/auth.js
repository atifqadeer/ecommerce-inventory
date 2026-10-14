import { defineStore } from 'pinia';
import api, { setUnauthorizedHandler, tokenStorage } from '@/lib/api';
import router from '@/router';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    loaded: false,
  }),

  getters: {
    isAuthenticated: (state) => !!state.user,
    firstName: (state) => state.user?.name?.split(' ')[0] ?? '',
  },

  actions: {
    /** Restore the session from a saved token (once per page load). */
    async init() {
      if (this.loaded) return;
      setUnauthorizedHandler(() => this.clear(true));
      if (tokenStorage.get()) {
        try {
          const { data } = await api.get('/auth/me');
          this.user = data;
        } catch {
          tokenStorage.clear();
        }
      }
      this.loaded = true;
    },

    async login(credentials) {
      const { data } = await api.post('/auth/login', credentials);
      this.setSession(data);
    },

    async register(payload) {
      const { data } = await api.post('/auth/register', payload);
      this.setSession(data);
    },

    async logout() {
      try {
        await api.post('/auth/logout');
      } finally {
        this.clear(true);
      }
    },

    async updateProfile(payload) {
      const { data } = await api.put('/auth/profile', payload);
      this.user = data;
    },

    /** Record that the product tour was finished or skipped, so it doesn't auto-start again. */
    async completeTour() {
      if (!this.user || this.user.tour_completed_at) return;
      this.user.tour_completed_at = new Date().toISOString();
      try {
        const { data } = await api.post('/auth/tour');
        this.user = data;
      } catch {
        // Not critical: worst case the tour offers itself again next visit.
      }
    },

    setSession({ user, token }) {
      tokenStorage.set(token);
      this.user = user;
    },

    clear(redirect = false) {
      tokenStorage.clear();
      this.user = null;
      if (redirect) router.push({ name: 'login' });
    },
  },
});
