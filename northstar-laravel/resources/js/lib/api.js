import axios from 'axios';

const TOKEN_KEY = 'northstar-token';

export const tokenStorage = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (token) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
};

const api = axios.create({
  baseURL: '/api',
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

api.interceptors.request.use((config) => {
  const token = tokenStorage.get();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Called when the server rejects our token; the auth store registers the handler.
let onUnauthorized = () => {};
export function setUnauthorizedHandler(handler) {
  onUnauthorized = handler;
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && tokenStorage.get()) onUnauthorized();
    return Promise.reject(error);
  },
);

/** Laravel validation errors as { field: 'first message' }. */
export function fieldErrors(error) {
  const errors = error?.response?.data?.errors ?? {};
  return Object.fromEntries(Object.entries(errors).map(([key, messages]) => [key, messages[0]]));
}

export function errorMessage(error, fallback = 'Something went wrong. Please try again.') {
  return error?.response?.data?.message ?? fallback;
}

/** Download a file from an authenticated endpoint. */
export async function download(url, params = {}, filename = 'export.csv') {
  const response = await api.get(url, { params, responseType: 'blob' });
  const disposition = response.headers['content-disposition'] ?? '';
  const match = disposition.match(/filename="?([^";]+)"?/);
  const link = document.createElement('a');
  link.href = URL.createObjectURL(response.data);
  link.download = match?.[1] ?? filename;
  link.click();
  URL.revokeObjectURL(link.href);
}

export default api;
