<template>
  <div class="space-y-6">
    <div>
      <p class="eyebrow">Settings</p>
      <h1 class="page-title">Account settings</h1>
      <p class="mt-1 text-sm text-muted-foreground">Manage your profile and sign-in details.</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
      <section class="card p-6">
        <h2 class="flex items-center gap-2 font-semibold"><User class="size-4" />Profile</h2>
        <form class="mt-5 space-y-4" novalidate @submit.prevent="saveProfile">
          <label class="label">Full name<input v-model="profile.name" autocomplete="name" class="input mt-2" /><p v-if="profileErrors.name" class="field-error">{{ profileErrors.name }}</p></label>
          <label class="label">Email<input v-model="profile.email" type="email" autocomplete="email" class="input mt-2" /><p v-if="profileErrors.email" class="field-error">{{ profileErrors.email }}</p></label>
          <label class="label">Company<input v-model="profile.company" autocomplete="organization" class="input mt-2" /></label>
          <div class="flex justify-end"><button type="submit" class="btn-primary" :disabled="savingProfile">Save profile</button></div>
        </form>
      </section>

      <section class="card p-6">
        <h2 class="flex items-center gap-2 font-semibold"><KeyRound class="size-4" />Password</h2>
        <form class="mt-5 space-y-4" novalidate @submit.prevent="savePassword">
          <div>
            <label for="current_password" class="label">Current password</label>
            <PasswordInput id="current_password" v-model="password.current_password" autocomplete="current-password" :invalid="!!passwordErrors.current_password" />
            <p v-if="passwordErrors.current_password" class="field-error">{{ passwordErrors.current_password }}</p>
          </div>
          <div>
            <label for="new_password" class="label">New password</label>
            <PasswordInput id="new_password" v-model="password.password" autocomplete="new-password" :invalid="!!passwordErrors.password" />
            <p v-if="passwordErrors.password" class="field-error">{{ passwordErrors.password }}</p>
          </div>
          <div>
            <label for="new_password_confirmation" class="label">Confirm new password</label>
            <PasswordInput id="new_password_confirmation" v-model="password.password_confirmation" autocomplete="new-password" />
          </div>
          <p class="text-xs text-muted-foreground">Changing your password signs you out on every other device.</p>
          <div class="flex justify-end"><button type="submit" class="btn-primary" :disabled="savingPassword">Update password</button></div>
        </form>
      </section>
    </div>

    <section class="card flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h2 class="flex items-center gap-2 font-semibold"><Compass class="size-4" />Product tour</h2>
        <p class="mt-1 text-sm text-muted-foreground">
          A one-minute walkthrough of the dashboard and where to find everything.
          <template v-if="auth.user.tour_completed_at">You finished it on {{ date(auth.user.tour_completed_at) }}.</template>
        </p>
      </div>
      <button class="btn-secondary shrink-0" @click="replayTour"><Compass class="size-4" />Replay tour</button>
    </section>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Compass, KeyRound, User } from 'lucide-vue-next';
import PasswordInput from '@/components/PasswordInput.vue';
import api, { errorMessage, fieldErrors } from '@/lib/api';
import { date } from '@/lib/format';
import { toast } from '@/lib/toast';
import { tourRequested } from '@/lib/tour';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();

function replayTour() {
  tourRequested.value = true;
  router.push({ name: 'overview' });
}

const profile = reactive({ name: auth.user.name, email: auth.user.email, company: auth.user.company ?? '' });
const profileErrors = ref({});
const savingProfile = ref(false);

const password = reactive({ current_password: '', password: '', password_confirmation: '' });
const passwordErrors = ref({});
const savingPassword = ref(false);

async function saveProfile() {
  savingProfile.value = true;
  profileErrors.value = {};
  try {
    await auth.updateProfile(profile);
    toast('Profile saved');
  } catch (error) {
    profileErrors.value = fieldErrors(error);
    if (!Object.keys(profileErrors.value).length) toast(errorMessage(error), 'error');
  } finally {
    savingProfile.value = false;
  }
}

async function savePassword() {
  savingPassword.value = true;
  passwordErrors.value = {};
  try {
    await api.put('/auth/password', password);
    Object.assign(password, { current_password: '', password: '', password_confirmation: '' });
    toast('Password updated');
  } catch (error) {
    passwordErrors.value = fieldErrors(error);
    if (!Object.keys(passwordErrors.value).length) toast(errorMessage(error), 'error');
  } finally {
    savingPassword.value = false;
  }
}
</script>
