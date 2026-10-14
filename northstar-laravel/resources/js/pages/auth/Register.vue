<template>
  <div>
    <h1 class="text-2xl font-semibold tracking-tight">Create your workspace</h1>
    <p class="mt-1 text-sm text-muted-foreground">Start managing every sales channel in one place.</p>

    <form class="mt-8 space-y-4" novalidate @submit.prevent="submit">
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="name" class="label">Full name</label>
          <input id="name" v-model="form.name" autocomplete="name" required class="input mt-2" :aria-invalid="!!errors.name" />
          <p v-if="errors.name" class="field-error">{{ errors.name }}</p>
        </div>
        <div>
          <label for="company" class="label">Company <span class="font-normal text-muted-foreground">(optional)</span></label>
          <input id="company" v-model="form.company" autocomplete="organization" class="input mt-2" />
          <p v-if="errors.company" class="field-error">{{ errors.company }}</p>
        </div>
      </div>
      <div>
        <label for="email" class="label">Work email</label>
        <input id="email" v-model="form.email" type="email" autocomplete="email" required class="input mt-2" :aria-invalid="!!errors.email" />
        <p v-if="errors.email" class="field-error">{{ errors.email }}</p>
      </div>
      <div>
        <label for="password" class="label">Password</label>
        <PasswordInput id="password" v-model="form.password" autocomplete="new-password" :invalid="!!errors.password" />
        <p v-if="errors.password" class="field-error">{{ errors.password }}</p>
        <p v-else class="mt-1 text-xs text-muted-foreground">At least 8 characters.</p>
      </div>
      <div>
        <label for="password_confirmation" class="label">Confirm password</label>
        <PasswordInput id="password_confirmation" v-model="form.password_confirmation" autocomplete="new-password" />
      </div>
      <label class="flex items-start gap-3 rounded-lg border border-border bg-card p-3 text-sm">
        <input v-model="form.sample_data" type="checkbox" class="mt-0.5 accent-indigo-600" />
        <span>
          <span class="block font-medium">Load sample data</span>
          <span class="block text-xs text-muted-foreground">Fill the workspace with demo channels, products and six months of orders so you can explore right away.</span>
        </span>
      </label>
      <button type="submit" class="btn-primary w-full py-2.5" :disabled="busy">
        <LoaderCircle v-if="busy" class="size-4 animate-spin" />{{ busy ? 'Setting up your workspace…' : 'Create account' }}
      </button>
    </form>

    <p class="mt-6 text-center text-sm text-muted-foreground">
      Already have an account?
      <RouterLink :to="{ name: 'login' }" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Sign in</RouterLink>
    </p>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { LoaderCircle } from 'lucide-vue-next';
import PasswordInput from '@/components/PasswordInput.vue';
import { useAuthStore } from '@/stores/auth';
import { errorMessage, fieldErrors } from '@/lib/api';
import { toast } from '@/lib/toast';

const router = useRouter();
const auth = useAuthStore();

const form = reactive({ name: '', company: '', email: '', password: '', password_confirmation: '', sample_data: true });
const errors = ref({});
const busy = ref(false);

async function submit() {
  busy.value = true;
  errors.value = {};
  try {
    await auth.register(form);
    toast(`Welcome to Northstar, ${auth.firstName}!`);
    router.push({ name: form.sample_data ? 'overview' : 'channels' });
  } catch (error) {
    errors.value = fieldErrors(error);
    if (!Object.keys(errors.value).length) toast(errorMessage(error), 'error');
  } finally {
    busy.value = false;
  }
}
</script>
