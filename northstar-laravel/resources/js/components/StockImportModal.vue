<template>
  <Modal :open="open" title="Import stock count" description="Set on-hand stock from a count or product list. Missing SKUs are created." @close="close">
    <div v-if="result" class="space-y-4">
      <dl class="grid grid-cols-3 gap-3">
        <div v-for="[label, value] in [['Created', result.created], ['Updated', result.updated], ['Unchanged', result.unchanged]]" :key="label" class="rounded-lg bg-muted/50 p-3">
          <dt class="text-xs text-muted-foreground">{{ label }}</dt>
          <dd class="mt-1 text-lg font-semibold tabular-nums">{{ number(value) }}</dd>
        </div>
      </dl>
      <ul v-if="result.warnings?.length" class="space-y-1 text-xs text-amber-700 dark:text-amber-300">
        <li v-for="w in result.warnings" :key="w">{{ w }}</li>
      </ul>
      <div class="flex justify-end"><button class="btn-primary" @click="close">Done</button></div>
    </div>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <ul class="space-y-1.5 rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground">
        <li>Each SKU's on-hand stock is set to the quantity in the file; the difference is logged as a stock movement. Rows for the same SKU (e.g. several locations) are added together.</li>
        <li>Reads the <button type="button" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" @click="download('/products/import-template', {}, 'northstar-stock-template.csv')">Northstar template</button>, Shopify's inventory export and Amazon's FBA inventory report.</li>
      </ul>
      <label class="label">File (CSV, TSV or TXT)
        <input ref="fileInput" type="file" accept=".csv,.tsv,.txt" class="input mt-2 file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1 file:text-xs file:font-medium" @change="file = $event.target.files[0] ?? null" />
        <p v-if="error" class="field-error">{{ error }}</p>
      </label>
      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" :disabled="busy" @click="close">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="busy || !file"><Upload class="size-4" />{{ busy ? 'Importing…' : 'Import' }}</button>
      </div>
    </form>
  </Modal>
</template>

<script setup>
import { ref, watch } from 'vue';
import { Upload } from 'lucide-vue-next';
import Modal from '@/components/Modal.vue';
import api, { download, errorMessage, fieldErrors } from '@/lib/api';
import { number } from '@/lib/format';

const props = defineProps({ open: Boolean });
const emit = defineEmits(['close', 'imported']);

const file = ref(null);
const fileInput = ref(null);
const busy = ref(false);
const error = ref('');
const result = ref(null);

watch(() => props.open, (open) => {
  if (!open) return;
  file.value = null;
  error.value = '';
  result.value = null;
  if (fileInput.value) fileInput.value.value = '';
});

const close = () => !busy.value && emit('close');

async function submit() {
  busy.value = true;
  error.value = '';
  const body = new FormData();
  body.append('file', file.value);
  try {
    result.value = (await api.post('/products/import', body)).data;
    emit('imported', result.value);
  } catch (e) {
    error.value = fieldErrors(e).file ?? errorMessage(e);
  } finally {
    busy.value = false;
  }
}
</script>
