<template>
  <div>
    <h1 class="text-2xl font-semibold tracking-tight">Welcome back</h1>
    <p class="mt-1 text-sm text-muted-foreground">Sign in to your Northstar workspace.</p>

    <p v-if="route.query.reset" class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300">
      Your password has been reset. Sign in with your new password.
    </p>

    <form class="mt-8 space-y-4" novalidate @submit.prevent="submit">
      <div>
        <label for="email" class="label">Email</label>
        <input id="email" v-model="form.email" type="email" autocomplete="email" required class="input mt-2" :aria-invalid="!!errors.email" />
        <p v-if="errors.email" class="field-error">{{ errors.email }}</p>
      </div>
      <div>
        <div class="flex items-center justify-between">
          <label for="password" class="label">Password</label>
          <RouterLink :to="{ name: 'forgot-password' }" class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">Forgot password?</RouterLink>
        </div>
        <PasswordInput id="password" v-model="form.password" autocomplete="current-password" :invalid="!!errors.password" />
        <p v-if="errors.password" class="field-error">{{ errors.password }}</p>
      </div>
      <button type="submit" class="btn-primary w-full py-2.5" :disabled="busy">
        <LoaderCircle v-if="busy" class="size-4 animate-spin" />Sign in
      </button>
    </form>

    <p class="mt-6 text-center text-sm text-muted-foreground">
      New to Northstar?
      <RouterLink :to="{ name: 'register' }" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Create an account</RouterLink>
    </p>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { LoaderCircle } from 'lucide-vue-next';
import PasswordInput from '@/components/PasswordInput.vue';
import { useAuthStore } from '@/stores/auth';
import { errorMessage, fieldErrors } from '@/lib/api';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const form = reactive({ email: '', password: '' });
const errors = ref({});
const busy = ref(false);

async function submit() {
  busy.value = true;
  errors.value = {};
  try {
    await auth.login(form);
    router.push(route.query.redirect?.startsWith('/') ? route.query.redirect : { name: 'overview' });
  } catch (error) {
    errors.value = fieldErrors(error);
    if (!Object.keys(errors.value).length) errors.value = { email: errorMessage(error) };
  } finally {
    busy.value = false;
  }
}
</script>
