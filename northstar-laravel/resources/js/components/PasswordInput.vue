<template>
  <div class="relative mt-2">
    <input
      :id="id"
      :value="modelValue"
      :type="visible ? 'text' : 'password'"
      :autocomplete="autocomplete"
      required
      class="input pr-10"
      :aria-invalid="invalid"
      @input="$emit('update:modelValue', $event.target.value)"
    />
    <button type="button" class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-muted-foreground hover:text-foreground" :aria-label="visible ? 'Hide password' : 'Show password'" @click="visible = !visible">
      <EyeOff v-if="visible" class="size-4" /><Eye v-else class="size-4" />
    </button>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { Eye, EyeOff } from 'lucide-vue-next';

defineProps({ id: String, modelValue: String, autocomplete: String, invalid: Boolean });
defineEmits(['update:modelValue']);

const visible = ref(false);
</script>
