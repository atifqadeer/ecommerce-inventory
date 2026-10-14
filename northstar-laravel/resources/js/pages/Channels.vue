<template>
  <div class="space-y-6">
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
      <div>
        <p class="eyebrow">Channels & settings</p>
        <h1 class="page-title">Stores & sales channels</h1>
        <p class="mt-1 text-sm text-muted-foreground">Every store you sell through, on any platform. Sync by API or upload their statements.</p>
      </div>
      <div class="flex gap-2">
        <button class="btn-secondary px-4 py-2.5" :disabled="!channels?.length" @click="openUpload()"><Upload class="size-4" />Upload statement</button>
        <button class="btn-primary px-4 py-2.5" @click="openConnect"><Plus class="size-4" />Add store</button>
      </div>
    </div>

    <LoadingState v-if="!channels" />

    <template v-else>
      <p v-if="!channels.length" class="card p-10 text-center text-sm text-muted-foreground">No stores yet. Add your Shopify, Amazon, Etsy or eBay stores, as many per platform as you run.</p>

      <section v-for="group in groups" :key="group.platform" class="space-y-3">
        <div class="flex items-center gap-2">
          <img :src="logo(group.platform)" alt="" class="size-5" />
          <h2 class="font-semibold">{{ group.platform }}</h2>
          <span class="text-xs text-muted-foreground">{{ group.stores.length }} store{{ group.stores.length === 1 ? '' : 's' }}</span>
        </div>
        <div class="grid gap-4 xl:grid-cols-2">
          <article v-for="c in group.stores" :key="c.id" class="card p-5" :class="{ 'opacity-70': c.status === 'disconnected' }">
            <div class="flex items-start justify-between gap-4">
              <div class="flex min-w-0 items-center gap-3">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-xl" :class="logoBg[c.platform]"><img :src="logo(c.platform)" :alt="`${c.platform} logo`" class="size-7" /></div>
                <div class="min-w-0">
                  <h3 class="truncate font-semibold">{{ c.name }}</h3>
                  <p class="truncate text-xs text-muted-foreground">{{ c.handle || c.platform }}</p>
                </div>
              </div>
              <button :aria-label="`Manage ${c.name}`" class="rounded-lg p-2 text-muted-foreground hover:bg-muted" @click="selected = c"><Ellipsis class="size-4" /></button>
            </div>
            <div class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
              <span class="inline-flex items-center gap-1.5 font-medium" :class="statusStyle[c.status].text"><span class="size-1.5 rounded-full" :class="statusStyle[c.status].dot" />{{ statusStyle[c.status].label }}</span>
              <span class="rounded-md bg-muted px-1.5 py-0.5 font-medium text-muted-foreground">{{ c.connection === 'api' ? 'API sync' : 'Statement uploads' }}</span>
              <span v-if="c.connection === 'api'" class="text-muted-foreground">{{ expiryText(c) }}</span>
              <span class="text-muted-foreground">{{ number(c.orders_count) }} orders</span>
              <span v-if="c.currency" class="text-muted-foreground">{{ c.currency }}</span>
            </div>
            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
              <label v-if="c.connection === 'api'" class="flex items-center gap-2 text-xs text-muted-foreground">
                <RefreshCw class="size-3.5" />Sync
                <select :value="c.sync_frequency" :disabled="c.status === 'disconnected'" class="rounded-md border border-border bg-background px-2 py-1 text-xs font-medium text-foreground" @change="updateFrequency(c, $event.target.value)">
                  <option v-for="f in workspace.meta?.sync_frequencies ?? []" :key="f">{{ f }}</option>
                </select>
              </label>
              <span v-else class="text-xs text-muted-foreground">Last upload {{ timeAgo(c.last_synced_at) }}</span>
              <div class="flex items-center gap-3">
                <span v-if="c.connection === 'api'" class="text-xs text-muted-foreground">Last sync {{ timeAgo(c.last_synced_at) }}</span>
                <button v-if="c.status === 'action_needed'" class="btn-primary px-2.5 py-1.5 text-xs" @click="selected = c">Fix connection</button>
                <button v-else-if="c.status === 'connected' && c.connection === 'api'" class="btn-secondary px-2.5 py-1.5 text-xs" :disabled="busy === c.id" @click="sync(c)">
                  <RefreshCw class="size-3.5" :class="{ 'animate-spin': busy === c.id }" />Sync now
                </button>
                <button v-if="c.status !== 'disconnected'" class="px-2.5 py-1.5 text-xs" :class="c.connection === 'api' ? 'btn-secondary' : 'btn-primary'" @click="openUpload(c.id)"><Upload class="size-3.5" />Upload statement</button>
              </div>
            </div>
          </article>
        </div>
      </section>
    </template>

    <section class="card">
      <div class="flex items-center justify-between border-b border-border p-5">
        <div>
          <h2 class="font-semibold">Statement uploads</h2>
          <p class="mt-1 text-xs text-muted-foreground">Reports imported into your stores. Removing an upload deletes what it added (orders it last updated, and new payments) and returns units to stock. Entries you typed in are kept.</p>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] text-left text-sm">
          <thead class="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr><th v-for="h in ['Uploaded', 'Store', 'File', 'Period', 'Records', 'Units', 'Charges', '']" :key="h" class="px-5 py-3 font-medium">{{ h }}</th></tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="i in imports" :key="i.id">
              <td class="px-5 py-3 text-xs text-muted-foreground">{{ relativeDay(i.created_at) }}</td>
              <td class="px-5 py-3"><ChannelBadge :name="i.channel?.name" :platform="i.channel?.platform" /></td>
              <td class="px-5 py-3">
                <p class="max-w-[220px] truncate font-medium" :title="i.filename">{{ i.filename }}</p>
                <p class="text-xs text-muted-foreground">{{ formatLabel(i.format) }}</p>
                <p v-if="i.warnings?.length" class="mt-1 text-xs text-amber-600 dark:text-amber-400" :title="i.warnings.join('\n')">{{ i.warnings.length }} warning{{ i.warnings.length === 1 ? '' : 's' }}</p>
              </td>
              <td class="px-5 py-3 text-xs text-muted-foreground">{{ i.period_from ? `${date(i.period_from)} – ${date(i.period_to)}` : '—' }}</td>
              <td class="px-5 py-3 text-xs tabular-nums">
                <template v-if="i.transactions || i.payouts">{{ number(i.transactions) }} transactions · {{ number(i.payouts) }} payouts<span class="block text-muted-foreground">{{ number(i.matched) }} already recorded</span></template>
                <template v-else>{{ number(i.orders_created) }} new / {{ number(i.orders_updated) }} updated orders</template>
              </td>
              <td class="px-5 py-3 tabular-nums">{{ number(i.units) }}</td>
              <td class="px-5 py-3 tabular-nums">{{ number(i.expenses) }}</td>
              <td class="px-5 py-3 text-right"><button class="rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-rose-600" :aria-label="`Remove ${i.filename}`" @click="removeImport(i)"><Trash2 class="size-4" /></button></td>
            </tr>
          </tbody>
        </table>
        <p v-if="!imports.length" class="py-10 text-center text-sm text-muted-foreground">No statements uploaded yet.</p>
      </div>
    </section>

    <section class="card">
      <div class="flex items-center justify-between border-b border-border p-5">
        <div>
          <h2 class="font-semibold">Sync log</h2>
          <p class="mt-1 text-xs text-muted-foreground">API syncs and statement imports across your stores.</p>
        </div>
        <button class="btn-secondary px-3 py-2 text-xs" @click="logLimit = logLimit === 8 ? 50 : 8; loadLogs()"><Clock3 class="size-3.5" />{{ logLimit === 8 ? 'View all logs' : 'Show fewer' }}</button>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[720px] text-left text-sm">
          <thead class="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr><th v-for="h in ['Time', 'Job type', 'Store', 'Records processed', 'Status', 'Error message']" :key="h" class="px-5 py-3 font-medium">{{ h }}</th></tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="log in logs" :key="log.id">
              <td class="px-5 py-3 text-xs text-muted-foreground">{{ relativeDay(log.created_at) }}</td>
              <td class="px-5 py-3 font-medium">{{ log.job_type }}</td>
              <td class="px-5 py-3">{{ log.channel?.name }}</td>
              <td class="px-5 py-3 tabular-nums">{{ log.records === null ? '—' : number(log.records) }}</td>
              <td class="px-5 py-3"><StatusBadge :status="log.status" /></td>
              <td class="px-5 py-3 text-xs text-rose-600">{{ log.error || '—' }}</td>
            </tr>
          </tbody>
        </table>
        <p v-if="!logs.length" class="py-10 text-center text-sm text-muted-foreground">No sync jobs yet.</p>
      </div>
    </section>

    <Modal :open="connect.open" title="Add store" :description="connect.platform ? `New store on ${connect.platform}.` :'Choose the platform the store sells on.'" @close="!connect.busy && (connect.open = false)">
      <div v-if="!connect.platform" class="grid grid-cols-2 gap-3">
        <button
          v-for="s in workspace.meta?.supported_channels ?? []"
          :key="s.name"
          class="flex items-center gap-3 rounded-xl border border-border p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-50/50 dark:hover:bg-indigo-950/30"
          @click="choosePlatform(s)"
        >
          <img :src="logo(s.name)" alt="" class="size-8" />
          <span><span class="block text-sm font-semibold">{{ s.name }}</span><span class="block text-xs text-muted-foreground">{{ storeCount(s.name) ? `${storeCount(s.name)} added` : s.api ? 'API or statements' : 'Statement uploads' }}</span></span>
          <ChevronRight class="ml-auto size-4 text-muted-foreground" />
        </button>
      </div>
      <form v-else class="space-y-4" @submit.prevent="submitConnect">
        <label class="label">Store name
          <input v-model="connect.name" :placeholder="`e.g. ${connect.platform === 'Amazon' ? 'Amazon UK' : 'Purest Peaks'}`" class="input mt-2" autocomplete="off" />
          <p v-if="connect.errors.name" class="field-error">{{ connect.errors.name }}</p>
        </label>

        <div v-if="platformInfo?.api" class="flex rounded-lg bg-muted p-1">
          <button
            v-for="[value, label] in [['api', 'Sync by API'], ['statements', 'Upload statements']]"
            :key="value"
            type="button"
            class="flex-1 rounded-md px-3 py-1.5 text-xs font-medium transition"
            :class="connect.connection === value ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground'"
            @click="connect.connection = value"
          >{{ label }}</button>
        </div>
        <p v-else class="rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground">{{ connect.platform }} stores are kept up to date by uploading their reports (orders, payments or transaction statements) after you add the store.</p>
        <p v-if="connect.errors.connection" class="field-error">{{ connect.errors.connection }}</p>

        <template v-if="connect.connection === 'api'">
          <label class="label">Store URL
            <input v-model="connect.handle" placeholder="your-store.myshopify.com" class="input mt-2" autocomplete="off" />
            <p v-if="connect.errors.handle" class="field-error">{{ connect.errors.handle }}</p>
          </label>
          <CredentialFields :form="connect" />
        </template>
        <div v-else class="grid gap-4 sm:grid-cols-[1fr_8rem]">
          <label class="label">Store URL or seller ID <span class="font-normal text-muted-foreground">(optional)</span>
            <input v-model="connect.handle" class="input mt-2" autocomplete="off" />
          </label>
          <label class="label">Currency
            <input v-model="connect.currency" maxlength="3" placeholder="USD" class="input mt-2 uppercase" autocomplete="off" />
            <p v-if="connect.errors.currency" class="field-error">{{ connect.errors.currency }}</p>
          </label>
        </div>

        <p v-if="connect.busy && connect.connection === 'api'" class="text-xs text-muted-foreground">Connecting and importing products and orders. The first sync can take a minute…</p>
        <div class="flex justify-between gap-2">
          <button type="button" class="btn-secondary" :disabled="connect.busy" @click="connect.platform = ''"><ArrowLeft class="size-4" />Back</button>
          <button type="submit" class="btn-primary" :disabled="connect.busy || !canConnect"><Link2 class="size-4" />{{ connect.busy ? 'Adding…' : 'Add store' }}</button>
        </div>
      </form>
    </Modal>

    <StatementUploadModal :open="upload.open" :channel-id="upload.channelId" @close="upload.open = false" @imported="afterImport" />

    <Drawer :open="!!selected" :label="`${selected?.name} settings`" width="max-w-sm" @close="selected = null">
      <div v-if="selected" class="p-6">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-xs font-medium uppercase tracking-widest text-indigo-600 dark:text-indigo-400">{{ selected.platform }} store</p>
            <h2 class="mt-1 text-xl font-semibold">{{ selected.name }}</h2>
          </div>
          <button aria-label="Close" @click="selected = null"><X class="size-5 text-muted-foreground" /></button>
        </div>

        <form class="mt-8 space-y-3" @submit.prevent="saveSettings">
          <label class="label">Store name<input v-model="settings.name" class="input mt-2" /></label>
          <label v-if="selected.connection !== 'api'" class="label">Store URL or seller ID<input v-model="settings.handle" class="input mt-2" /></label>
          <label class="label">Currency<input v-model="settings.currency" maxlength="3" placeholder="USD" class="input mt-2 uppercase" /></label>
          <p v-if="settings.errors.name || settings.errors.currency" class="field-error">{{ settings.errors.name || settings.errors.currency }}</p>
          <button type="submit" class="btn-secondary w-full"><Settings2 class="size-4" />Save store settings</button>
        </form>

        <form v-if="selected.connection === 'api' || apiPlatforms.includes(selected.platform)" class="mt-8 space-y-4 border-t border-border pt-6" @submit.prevent="updateCredentials">
          <h3 class="text-sm font-semibold">{{ selected.connection === 'api' ? 'API credentials' : 'Switch to API sync' }}</h3>
          <p v-if="selected.connection === 'api'" class="text-sm"><span class="text-muted-foreground">Store</span><br /><span class="font-medium">{{ selected.handle }}</span></p>
          <label v-else class="label">Store URL
            <input v-model="credentials.handle" placeholder="your-store.myshopify.com" class="input mt-2" autocomplete="off" />
            <p v-if="credentials.errors.handle" class="field-error">{{ credentials.errors.handle }}</p>
          </label>
          <CredentialFields :form="credentials" />
          <button type="submit" class="btn-secondary w-full" :disabled="credentials.busy || !hasCredentials(credentials)"><KeyRound class="size-4" />{{ credentials.busy ? 'Checking…' : selected.connection === 'api' ? 'Update API credentials' : 'Connect API' }}</button>
        </form>

        <div class="mt-6 space-y-3">
          <button v-if="selected.status !== 'connected' || selected.auth_type === 'client_credentials'" class="flex w-full items-center gap-3 rounded-lg border border-border p-3 text-left text-sm hover:bg-muted" @click="reauthorize(selected)">
            <RefreshCw class="size-4" />{{ selected.status === 'disconnected' ? 'Reconnect store' : 'Refresh access token' }}<ChevronRight class="ml-auto size-4" />
          </button>
          <button v-if="selected.status !== 'disconnected'" class="flex w-full items-center gap-3 rounded-lg border border-rose-200 p-3 text-left text-sm text-rose-600 hover:bg-rose-50 dark:border-rose-900 dark:hover:bg-rose-950/40" @click="disconnect(selected)">
            <Unplug class="size-4" />Disconnect store<ChevronRight class="ml-auto size-4" />
          </button>
          <div class="rounded-lg bg-muted/50 p-4 text-xs text-muted-foreground">
            <TriangleAlert class="mb-2 size-4 text-amber-500" />Disconnecting pauses syncs and uploads for this store. Existing orders and listings are kept.
          </div>
        </div>
      </div>
    </Drawer>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ArrowLeft, ChevronRight, Clock3, Ellipsis, KeyRound, Link2, Plus, RefreshCw, Settings2, Trash2, TriangleAlert, Unplug, Upload, X } from 'lucide-vue-next';
import ChannelBadge from '@/components/ChannelBadge.vue';
import CredentialFields from '@/components/CredentialFields.vue';
import Drawer from '@/components/Drawer.vue';
import LoadingState from '@/components/LoadingState.vue';
import Modal from '@/components/Modal.vue';
import StatementUploadModal from '@/components/StatementUploadModal.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import api, { errorMessage, fieldErrors } from '@/lib/api';
import { date, daysUntil, number, relativeDay, timeAgo } from '@/lib/format';
import { toast } from '@/lib/toast';
import { useWorkspaceStore } from '@/stores/workspace';

const workspace = useWorkspaceStore();

const channels = ref(null);
const logs = ref([]);
const imports = ref([]);
const logLimit = ref(8);
const busy = ref(null);
const selected = ref(null);
const upload = reactive({ open: false, channelId: null });

const blankCredentials = () => ({ auth_type: 'access_token', access_token: '', client_id: '', client_secret: '', handle: '', busy: false, errors: {} });
const connect = reactive({ open: false, platform: '', name: '', connection: 'statements', currency: '', ...blankCredentials() });
const credentials = reactive(blankCredentials());
const settings = reactive({ name: '', handle: '', currency: '', errors: {} });

const logo = (platform) => `https://cdn.jsdelivr.net/gh/glincker/thesvg@main/public/icons/${platform.toLowerCase()}/default.svg`;
const logoBg = { Shopify: 'bg-emerald-50', Amazon: 'bg-orange-50', Etsy: 'bg-rose-50', eBay: 'bg-blue-50' };
const statusStyle = {
  connected: { label: 'Active', text: 'text-emerald-600', dot: 'bg-emerald-500' },
  action_needed: { label: 'Action needed', text: 'text-rose-600', dot: 'bg-rose-500' },
  disconnected: { label: 'Disconnected', text: 'text-muted-foreground', dot: 'bg-slate-400' },
};

const apiPlatforms = computed(() => (workspace.meta?.supported_channels ?? []).filter((s) => s.api).map((s) => s.name));
const platformInfo = computed(() => workspace.meta?.supported_channels?.find((s) => s.name === connect.platform));
const groups = computed(() => {
  const order = (workspace.meta?.supported_channels ?? []).map((s) => s.name);
  return order
    .map((platform) => ({ platform, stores: (channels.value ?? []).filter((c) => c.platform === platform) }))
    .filter((g) => g.stores.length);
});
const storeCount = (platform) => channels.value?.filter((c) => c.platform === platform).length ?? 0;
const formatLabel = (key) => workspace.meta?.statement_formats?.find((f) => f.key === key)?.label ?? key;

const hasCredentials = (form) => (form.auth_type === 'access_token' ? !!form.access_token : !!(form.client_id && form.client_secret));
const credentialPayload = ({ auth_type, access_token, client_id, client_secret }) =>
  auth_type === 'access_token' ? { auth_type, access_token } : { auth_type, client_id, client_secret };
const canConnect = computed(() => connect.name && (connect.connection !== 'api' || (connect.handle && hasCredentials(connect))));

/** Toast the outcome of a sync, given the logs the API returned. */
function reportSync(channel, logs) {
  if (!logs.length) return;
  const failed = logs.find((l) => l.status === 'failed');
  if (failed) toast(`${channel.name} sync failed: ${failed.error}`, 'error');
  else toast(`${channel.name} synced · ${logs.map((l) => `${number(l.records)} ${l.job_type.replace(' sync', '').toLowerCase()}`).join(', ')}`);
}

function expiryText(channel) {
  if (channel.status === 'disconnected') return 'Syncing paused';
  if (channel.auth_type === 'access_token') return 'Admin API token';
  if (channel.auth_type === 'client_credentials') return 'Token renews automatically';
  if (!channel.token_expires_at) return '';
  const days = daysUntil(channel.token_expires_at);
  if (days < 0) return 'Token expired';
  return days <= 7 ? `Token expires in ${days} day${days === 1 ? '' : 's'}` : `Renews in ${days} days`;
}

async function load() {
  channels.value = (await api.get('/channels')).data;
}

async function loadLogs() {
  logs.value = (await api.get('/channels/logs', { params: { limit: logLimit.value } })).data;
}

async function loadImports() {
  imports.value = (await api.get('/statement-imports', { params: { limit: 50 } })).data;
}

async function refreshAll() {
  await Promise.all([load(), loadLogs(), loadImports(), workspace.refresh()]);
  if (selected.value) selected.value = channels.value.find((c) => c.id === selected.value.id) ?? null;
}

async function run(channel, request, message) {
  busy.value = channel.id;
  try {
    await request();
    if (message) toast(message);
    await refreshAll();
  } catch (error) {
    toast(errorMessage(error), 'error');
  } finally {
    busy.value = null;
  }
}

async function sync(channel) {
  busy.value = channel.id;
  try {
    const { data } = await api.post(`/channels/${channel.id}/sync`);
    reportSync(channel, data.logs);
    await refreshAll();
  } catch (error) {
    toast(errorMessage(error), 'error');
  } finally {
    busy.value = null;
  }
}

async function reauthorize(channel) {
  await run(channel, async () => {
    const { data } = await api.post(`/channels/${channel.id}/reauthorize`);
    data.logs.length ? reportSync(channel, data.logs) : toast(`${channel.name} reconnected`);
  });
}

async function updateCredentials() {
  const channel = selected.value;
  credentials.busy = true;
  credentials.errors = {};
  try {
    const payload = { ...credentialPayload(credentials), ...(channel.connection === 'api' ? {} : { handle: credentials.handle }) };
    const { data } = await api.post(`/channels/${channel.id}/reauthorize`, payload);
    Object.assign(credentials, blankCredentials());
    reportSync(channel, data.logs);
    await refreshAll();
  } catch (error) {
    credentials.errors = fieldErrors(error);
    if (!Object.keys(credentials.errors).length) toast(errorMessage(error), 'error');
  } finally {
    credentials.busy = false;
  }
}

async function saveSettings() {
  const channel = selected.value;
  settings.errors = {};
  try {
    const payload = { name: settings.name, currency: settings.currency || null, ...(channel.connection === 'api' ? {} : { handle: settings.handle }) };
    await api.patch(`/channels/${channel.id}`, payload);
    toast('Store settings saved');
    await refreshAll();
  } catch (error) {
    settings.errors = fieldErrors(error);
    if (!Object.keys(settings.errors).length) toast(errorMessage(error), 'error');
  }
}

const updateFrequency = (c, sync_frequency) => run(c, () => api.patch(`/channels/${c.id}`, { sync_frequency }), `${c.name} will sync ${sync_frequency.toLowerCase()}`);

function disconnect(channel) {
  if (!confirm(`Disconnect ${channel.name}?`)) return;
  run(channel, () => api.post(`/channels/${channel.id}/disconnect`), `${channel.name} disconnected`);
}

async function removeImport(item) {
  if (!confirm(`Remove ${item.filename}? Orders it last updated are deleted and their units go back into stock.`)) return;
  try {
    await api.delete(`/statement-imports/${item.id}`);
    toast('Upload removed');
    await refreshAll();
  } catch (error) {
    toast(errorMessage(error), 'error');
  }
}

function openConnect() {
  Object.assign(connect, { open: true, platform: '', name: '', connection: 'statements', currency: '', ...blankCredentials() });
}

function choosePlatform(platform) {
  const count = storeCount(platform.name);
  Object.assign(connect, { platform: platform.name, connection: platform.api ? 'api' : 'statements', name: count ? '' : platform.name, errors: {} });
}

function openUpload(channelId = null) {
  Object.assign(upload, { open: true, channelId });
}

async function afterImport() {
  await refreshAll();
}

async function submitConnect() {
  connect.busy = true;
  connect.errors = {};
  try {
    const payload = {
      platform: connect.platform,
      name: connect.name,
      connection: connect.connection,
      handle: connect.handle || null,
      ...(connect.connection === 'api' ? credentialPayload(connect) : { currency: connect.currency || null }),
    };
    const { data } = await api.post('/channels', payload);
    connect.open = false;
    data.logs.length ? reportSync(data.channel, data.logs) : toast(`${data.channel.name} added`);
    await refreshAll();
    if (data.channel.connection === 'statements') openUpload(data.channel.id);
  } catch (error) {
    connect.errors = fieldErrors(error);
    if (!Object.keys(connect.errors).length) toast(errorMessage(error), 'error');
  } finally {
    connect.busy = false;
  }
}

watch(selected, (channel, previous) => {
  Object.assign(settings, { name: channel?.name ?? '', handle: channel?.handle ?? '', currency: channel?.currency ?? '', errors: {} });
  if (channel?.id !== previous?.id) Object.assign(credentials, blankCredentials(), { auth_type: channel?.auth_type ?? 'access_token' });
});

onMounted(() => Promise.all([load(), loadLogs(), loadImports()]));
</script>
