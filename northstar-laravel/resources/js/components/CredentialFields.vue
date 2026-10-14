<template>
  <div class="space-y-4">
    <div class="flex rounded-lg bg-muted p-1">
      <button
        v-for="[value, label] in [['access_token', 'Admin API access token'], ['client_credentials', 'Client ID & secret']]"
        :key="value"
        type="button"
        class="flex-1 rounded-md px-3 py-1.5 text-xs font-medium transition"
        :class="form.auth_type === value ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground'"
        @click="form.auth_type = value"
      >{{ label }}</button>
    </div>

    <label v-if="form.auth_type === 'access_token'" class="label">Admin API access token
      <PasswordInput v-model="form.access_token" autocomplete="off" :invalid="!!form.errors.access_token" />
      <p v-if="form.errors.access_token" class="field-error">{{ form.errors.access_token }}</p>
    </label>
    <template v-else>
      <label class="label">Client ID
        <input v-model="form.client_id" class="input mt-2" autocomplete="off" :aria-invalid="!!form.errors.client_id" />
        <p v-if="form.errors.client_id" class="field-error">{{ form.errors.client_id }}</p>
      </label>
      <label class="label">Client secret
        <PasswordInput v-model="form.client_secret" autocomplete="off" :invalid="!!form.errors.client_secret" />
        <p v-if="form.errors.client_secret" class="field-error">{{ form.errors.client_secret }}</p>
      </label>
    </template>

    <p class="rounded-lg bg-muted/50 p-3 text-xs leading-relaxed text-muted-foreground">
      <template v-if="form.auth_type === 'access_token'">From a custom app in your Shopify admin: Settings → Apps → Develop apps → API credentials. The token starts with <code>shpat_</code>.</template>
      <template v-else>From an app in the Shopify Dev Dashboard (Settings → Credentials). The app must be installed on this store and belong to the same organization; tokens renew automatically.</template>
      The app needs the Admin API scopes <code>read_products</code>, <code>read_inventory</code> and <code>read_orders</code>.
    </p>
  </div>
</template>

<script setup>
import PasswordInput from '@/components/PasswordInput.vue';

/** form: reactive { auth_type, access_token, client_id, client_secret, errors } */
defineProps({ form: { type: Object, required: true } });
</script>
