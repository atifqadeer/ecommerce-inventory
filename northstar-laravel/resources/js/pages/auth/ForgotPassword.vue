<template>
  <div>
    <h1 class="text-2xl font-semibold tracking-tight">Reset your password</h1>
    <p class="mt-1 text-sm text-muted-foreground">Enter your account email and we'll send you a reset link.</p>

    <div v-if="sent" class="mt-8 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300">
      <Mail class="mb-2 size-5" />
      {{ sent }}
    </div>

    <form v-else class="mt-8 space-y-4" novalidate @submit.prevent="submit">
      <div>
        <label for="email" class="label">Email</label>
        <input id="email" v-model="email" type="email" autocomplete="email" required class="input mt-2" :aria-invalid="!!errors.email" />
        <p v-if="errors.email" class="field-error">{{ errors.email }}</p>
      </div>
      <button type="submit" class="btn-primary w-full py-2.5" :disabled="busy">
        <LoaderCircle v-if="busy" class="size-4 animate-spin" />Send reset link
      </button>
    </form>

    <p class="mt-6 text-center text-sm text-muted-foreground">
      <RouterLink :to="{ name: 'login' }" class="inline-flex items-center gap-1 font-medium text-indigo-600 hover:underline dark:text-indigo-400"><ArrowLeft class="size-3.5" />Back to sign in</RouterLink>
    </p>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { ArrowLeft, LoaderCircle, Mail } from 'lucide-vue-next';
import api, { errorMessage, fieldErrors } from '@/lib/api';

const email = ref('');
const errors = ref({});
const busy = ref(false);
const sent = ref('');

async function submit() {
  busy.value = true;
  errors.value = {};
  try {
    const { data } = await api.post('/auth/forgot-password', { email: email.value });
    sent.value = data.message;
  } catch (error) {
    errors.value = fieldErrors(error);
    if (!Object.keys(errors.value).length) errors.value = { email: errorMessage(error) };
  } finally {
    busy.value = false;
  }
}
</script>
