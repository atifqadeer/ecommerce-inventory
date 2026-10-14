import { reactive } from 'vue';

export const toasts = reactive([]);
let nextId = 1;

export function toast(message, type = 'success') {
  const id = nextId++;
  toasts.push({ id, message, type });
  setTimeout(() => {
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) toasts.splice(index, 1);
  }, 4000);
}
