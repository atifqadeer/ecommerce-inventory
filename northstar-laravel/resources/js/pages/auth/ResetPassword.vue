<template>
  <div>
    <h1 class="text-2xl font-semibold tracking-tight">Choose a new password</h1>
    <p class="mt-1 text-sm text-muted-foreground">Resetting the password for <span class="font-medium text-foreground">{{ form.email || 'your account' }}</span>.</p>

    <p v-if="!form.token" class="mt-8 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-300">
      This reset link is incomplete. Request a new one from the
      <RouterLink :to="{ name: 'forgot-password' }" class="font-medium underline">forgot password</RouterLink> page.
    </p>

    <form v-else class="mt-8 space-y-4" novalidate @submit.prevent="submit">
      <p v-if="errors.email" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-300">{{ errors.email }}</p>
      <div>
        <label for="password" class="label">New password</label>
        <PasswordInput id="password" v-model="form.password" autocomplete="new-password" :invalid="!!errors.password" />
        <p v-if="errors.password" class="field-error">{{ errors.password }}</p>
      </div>
      <div>
        <label for="password_confirmation" class="label">Confirm new password</label>
        <PasswordInput id="password_confirmation" v-model="form.password_confirmation" autocomplete="new-password" />
      </div>
      <button type="submit" class="btn-primary w-full py-2.5" :disabled="busy">
        <LoaderCircle v-if="busy" class="size-4 animate-spin" />Reset password
      </button>
    </form>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { LoaderCircle } from 'lucide-vue-next';
import PasswordInput from '@/components/PasswordInput.vue';
import api, { errorMessage, fieldErrors } from '@/lib/api';

const route = useRoute();
const router = useRouter();

const form = reactive({ token: route.query.token ?? '', email: route.query.email ?? '', password: '', password_confirmation: '' });
const errors = ref({});
const busy = ref(false);

async function submit() {
  busy.value = true;
  errors.value = {};
  try {
    await api.post('/auth/reset-password', form);
    router.push({ name: 'login', query: { reset: 1 } });
  } catch (error) {
    errors.value = fieldErrors(error);
    if (!Object.keys(errors.value).length) errors.value = { email: errorMessage(error) };
  } finally {
    busy.value = false;
  }
}
</script>
