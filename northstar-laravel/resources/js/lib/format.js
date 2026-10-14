import { shallowRef } from 'vue';

const numberFormat = new Intl.NumberFormat('en-US');

// Amounts always show cents: sellers reconcile to the cent against marketplace statements.
const currencyFormats = (currency) => ({
  whole: new Intl.NumberFormat('en-US', { style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }),
  cents: new Intl.NumberFormat('en-US', { style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }),
});

// Reactive so components re-render once the workspace currency is known.
const formats = shallowRef(currencyFormats('USD'));

/** Format money in the workspace's store currency (from the connected channel, USD by default). */
export function setCurrency(currency = 'USD') {
  if (formats.value.whole.resolvedOptions().currency !== currency) formats.value = currencyFormats(currency);
}

export const money = (value) => formats.value.whole.format(Number(value) || 0);
export const moneyExact = (value) => formats.value.cents.format(Number(value) || 0);
export const number = (value) => numberFormat.format(Number(value) || 0);

/** -1234.5 → "-$1,234.50" */
export const signedMoney = (value) => (value < 0 ? '-' : '') + money(Math.abs(value));

export const percent = (value) => (value === null || value === undefined ? '—' : `${value > 0 ? '+' : ''}${value.toFixed(1)}%`);

export const date = (value, options = { month: 'short', day: 'numeric', year: 'numeric' }) =>
  value ? new Date(value).toLocaleDateString('en-US', options) : '—';

export const dateTime = (value) =>
  value ? new Date(value).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—';

export const time = (value) => (value ? new Date(value).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) : '—');

/** "Today, 10:42 AM", "Yesterday, 6:32 PM", or a date. */
export function relativeDay(value) {
  const d = new Date(value);
  const today = new Date();
  const yesterday = new Date();
  yesterday.setDate(today.getDate() - 1);
  if (d.toDateString() === today.toDateString()) return `Today, ${time(value)}`;
  if (d.toDateString() === yesterday.toDateString()) return `Yesterday, ${time(value)}`;
  return dateTime(value);
}

export function timeAgo(value) {
  if (!value) return 'never';
  const seconds = Math.round((Date.now() - new Date(value).getTime()) / 1000);
  if (seconds < 60) return 'just now';
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `${minutes} min ago`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours} hr ago`;
  return `${Math.round(hours / 24)} days ago`;
}

export function daysUntil(value) {
  return Math.ceil((new Date(value).getTime() - Date.now()) / 86_400_000);
}

export const capitalize = (value = '') => value.charAt(0).toUpperCase() + value.slice(1);

export const initials = (name = '') =>
  name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('');
