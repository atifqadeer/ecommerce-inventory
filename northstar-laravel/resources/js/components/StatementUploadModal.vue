<template>
  <Modal :open="open" title="Upload statement" :description="result ? `${result.filename} imported into ${store?.name}.` : 'Import orders, fees and charges from a marketplace report.'" width="max-w-xl" @close="close">
    <div v-if="result" class="space-y-4">
      <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div v-for="[label, value] in summary" :key="label" class="rounded-lg bg-muted/50 p-3">
          <dt class="text-xs text-muted-foreground">{{ label }}</dt>
          <dd class="mt-1 text-lg font-semibold tabular-nums">{{ value }}</dd>
        </div>
      </dl>
      <p v-if="result.period_from" class="text-xs text-muted-foreground">Covers {{ date(result.period_from) }} – {{ date(result.period_to) }} · {{ formatLabel(result.format) }}</p>
      <ul v-if="result.warnings?.length" class="space-y-1.5 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
        <li v-for="w in result.warnings" :key="w" class="flex gap-2"><TriangleAlert class="mt-0.5 size-3.5 shrink-0" />{{ w }}</li>
      </ul>
      <div class="flex justify-end gap-2">
        <button class="btn-secondary" @click="reset">Upload another</button>
        <button class="btn-primary" @click="close">Done</button>
      </div>
    </div>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <label class="label">Store
        <select v-model="form.channel_id" class="input mt-2">
          <option v-for="c in channels" :key="c.id" :value="c.id">{{ c.name }} · {{ c.platform }}</option>
        </select>
        <p v-if="store?.connection === 'api'" class="mt-1.5 text-xs text-muted-foreground">This store syncs stock from its API, so uploads add orders and fees but don't change stock.</p>
      </label>

      <label class="label">Report type
        <select v-model="form.format" class="input mt-2">
          <option value="">Detect automatically</option>
          <option v-for="f in formats" :key="f.key" :value="f.key">{{ f.label }}</option>
        </select>
      </label>

      <ul class="space-y-1.5 rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground">
        <li v-for="f in hints" :key="f.key"><span class="font-medium text-foreground">{{ f.label }}:</span> {{ f.hint }}</li>
        <li>
          Other reports: fill in the
          <button type="button" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" @click="download('/statement-imports/template', {}, 'northstar-statement-template.csv')">Northstar template</button>.
        </li>
      </ul>

      <label class="label">File (CSV, TSV or TXT)
        <input ref="fileInput" type="file" accept=".csv,.tsv,.txt" class="input mt-2 file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1 file:text-xs file:font-medium" @change="form.file = $event.target.files[0] ?? null" />
        <p v-if="errors.file || errors.format" class="field-error">{{ errors.file || errors.format }}</p>
      </label>

      <p v-if="busy" class="text-xs text-muted-foreground">Importing… large reports can take a minute.</p>
      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" :disabled="busy" @click="close">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="busy || !form.file || !form.channel_id"><Upload class="size-4" />{{ busy ? 'Importing…' : 'Upload' }}</button>
      </div>
    </form>
  </Modal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { TriangleAlert, Upload } from 'lucide-vue-next';
import Modal from '@/components/Modal.vue';
import api, { download, errorMessage, fieldErrors } from '@/lib/api';
import { date, number } from '@/lib/format';
import { toast } from '@/lib/toast';
import { useWorkspaceStore } from '@/stores/workspace';

const props = defineProps({ open: Boolean, channelId: Number });
const emit = defineEmits(['close', 'imported']);

const workspace = useWorkspaceStore();
const form = reactive({ channel_id: null, format: '', file: null });
const errors = ref({});
const busy = ref(false);
const result = ref(null);
const fileInput = ref(null);

const channels = computed(() => workspace.channels.filter((c) => c.status !== 'disconnected'));
const store = computed(() => workspace.channels.find((c) => c.id === form.channel_id));
const formats = computed(() => (workspace.meta?.statement_formats ?? []).filter((f) => !f.platform || f.platform === store.value?.platform));
const hints = computed(() => formats.value.filter((f) => f.platform && (!form.format || f.key === form.format)));
const formatLabel = (key) => workspace.meta?.statement_formats?.find((f) => f.key === key)?.label ?? key;

const summary = computed(() => {
  const r = result.value;
  // Payment files carry transactions/payouts rather than orders; show what the file actually contained.
  const payments = r.transactions || r.payouts;
  return (payments
    ? [
        ['Transactions', number(r.transactions)],
        ['Payouts', number(r.payouts)],
        ['Already recorded', number(r.matched)],
        ['New', number(r.transactions + r.payouts - r.matched)],
        ['Rows read', number(r.rows)],
      ]
    : [
        ['New orders', number(r.orders_created)],
        ['Updated orders', number(r.orders_updated)],
        ['Units taken from stock', number(r.units)],
        ['Fees & charges', number(r.expenses)],
        ['New products', number(r.products_created)],
        ['Rows read', number(r.rows)],
      ]);
});

function reset() {
  Object.assign(form, { format: '', file: null });
  errors.value = {};
  result.value = null;
  if (fileInput.value) fileInput.value.value = '';
}

function close() {
  if (!busy.value) emit('close');
}

watch(() => props.open, (open) => {
  if (!open) return;
  reset();
  form.channel_id = props.channelId ?? channels.value[0]?.id ?? null;
});
watch(() => form.channel_id, () => (form.format = ''));

async function submit() {
  busy.value = true;
  errors.value = {};
  const body = new FormData();
  body.append('file', form.file);
  if (form.format) body.append('format', form.format);
  try {
    const { data } = await api.post(`/channels/${form.channel_id}/statements`, body);
    result.value = data;
    emit('imported', data);
  } catch (error) {
    errors.value = fieldErrors(error);
    if (!Object.keys(errors.value).length) toast(errorMessage(error), 'error');
  } finally {
    busy.value = false;
  }
}
</script>
