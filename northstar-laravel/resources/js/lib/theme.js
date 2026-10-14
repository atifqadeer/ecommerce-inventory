import { ref } from 'vue';

const KEY = 'northstar-theme';

export const isDark = ref(false);

function read() {
  try {
    return localStorage.getItem(KEY);
  } catch {
    return null;
  }
}

function apply() {
  document.documentElement.classList.toggle('dark', isDark.value);
}

export function initTheme() {
  const saved = read();
  isDark.value = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
  apply();
}

export function toggleTheme() {
  isDark.value = !isDark.value;
  apply();
  try {
    localStorage.setItem(KEY, isDark.value ? 'dark' : 'light');
  } catch {
    // Storage unavailable (private mode); the choice just won't persist.
  }
}
