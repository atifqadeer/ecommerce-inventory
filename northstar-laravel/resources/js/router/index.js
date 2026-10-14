import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const routes = [
  {
    // The layout gets its own path so it never matches "/" (which belongs to the dashboard);
    // the children use absolute URLs so the sign-in pages still live at /login, /register, …
    path: '/auth',
    redirect: { name: 'login' },
    component: () => import('@/layouts/AuthLayout.vue'),
    meta: { guest: true },
    children: [
      { path: '/login', name: 'login', component: () => import('@/pages/auth/Login.vue'), meta: { title: 'Sign in' } },
      { path: '/register', name: 'register', component: () => import('@/pages/auth/Register.vue'), meta: { title: 'Create account' } },
      { path: '/forgot-password', name: 'forgot-password', component: () => import('@/pages/auth/ForgotPassword.vue'), meta: { title: 'Forgot password' } },
      { path: '/reset-password', name: 'reset-password', component: () => import('@/pages/auth/ResetPassword.vue'), meta: { title: 'Reset password' } },
    ],
  },
  {
    path: '/',
    component: () => import('@/layouts/AppLayout.vue'),
    meta: { auth: true },
    children: [
      { path: '', name: 'overview', component: () => import('@/pages/Overview.vue'), meta: { title: 'Overview' } },
      { path: 'orders', name: 'orders', component: () => import('@/pages/orders/Orders.vue'), meta: { title: 'Orders' } },
      { path: 'orders/:id', name: 'order', component: () => import('@/pages/orders/OrderShow.vue'), meta: { title: 'Orders' }, props: true },
      { path: 'inventory', name: 'inventory', component: () => import('@/pages/Inventory.vue'), meta: { title: 'Inventory' } },
      { path: 'products', name: 'products', component: () => import('@/pages/Products.vue'), meta: { title: 'Products' } },
      { path: 'purchasing', name: 'purchasing', component: () => import('@/pages/purchasing/Purchasing.vue'), meta: { title: 'Purchasing' } },
      { path: 'channels', name: 'channels', component: () => import('@/pages/Channels.vue'), meta: { title: 'Channels' } },
      { path: 'finance', name: 'finance', component: () => import('@/pages/finance/ProfitLoss.vue'), meta: { title: 'Finance' } },
      { path: 'cash-flow', name: 'cash-flow', component: () => import('@/pages/finance/CashFlow.vue'), meta: { title: 'Cash Flow' } },
      { path: 'payments', name: 'payments', component: () => import('@/pages/finance/Payments.vue'), meta: { title: 'Payments' } },
      { path: 'balance-sheet', name: 'balance-sheet', component: () => import('@/pages/finance/BalanceSheet.vue'), meta: { title: 'Balance Sheet' } },
      { path: 'reports', name: 'reports', component: () => import('@/pages/Reports.vue'), meta: { title: 'Reports' } },
      { path: 'settings', name: 'settings', component: () => import('@/pages/Settings.vue'), meta: { title: 'Settings' } },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/NotFound.vue'), meta: { title: 'Not found' } },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
});

router.beforeEach(async (to) => {
  const auth = useAuthStore();
  await auth.init();

  if (to.matched.some((r) => r.meta.auth) && !auth.isAuthenticated) {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} };
  }
  if (to.matched.some((r) => r.meta.guest) && auth.isAuthenticated) {
    return { name: 'overview' };
  }
});

router.afterEach((to) => {
  document.title = `${to.meta.title ? `${to.meta.title} · ` : ''}Northstar`;
});

export default router;
