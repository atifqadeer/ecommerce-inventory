import { ref } from 'vue';
import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';

/** Set when the user asks to replay the tour; the Overview page starts it once its data has loaded. */
export const tourRequested = ref(false);

let activeTour = null;

const isDesktop = () => window.matchMedia('(min-width: 1024px)').matches;

const navStep = (name, title, description) => ({
  element: `[data-tour="nav-${name}"]`,
  popover: { title, description, side: 'right', align: 'center' },
});

function buildSteps({ firstName, hasChannels }) {
  const channelsText = hasChannels
    ? 'Connect marketplaces, choose how often they sync, and fix connection problems. A red badge means a channel needs your attention.'
    : 'Start here: connect your first marketplace (Shopify, Amazon, Etsy or eBay) so orders and stock begin syncing.';

  const navigation = isDesktop()
    ? [
        navStep('orders', 'Orders', 'Every order from every channel in one list. Create manual orders, mark them shipped and issue refunds. The badge counts orders waiting to ship.'),
        navStep('inventory', 'Inventory', 'Stock on hand, available and reserved for each SKU, per warehouse and channel. Select products to adjust stock in bulk.'),
        navStep('products', 'Products', 'Your master catalog: prices, unit costs, reorder points and which channels each SKU is listed on.'),
        navStep('channels', 'Channels', channelsText),
        navStep('finance', 'Profit & Loss', 'See how revenue turns into net profit, by channel, month or SKU, compared with the previous period.'),
        navStep('balance-sheet', 'Balance Sheet', 'Assets, liabilities and equity on any date. Click a line to see the journal entries behind it.'),
        navStep('reports', 'Reports', 'Download CSV exports and record expenses like advertising and software so your P&L stays accurate.'),
      ]
    : [
        {
          element: '[data-tour="menu"]',
          popover: { title: 'Everything else is in the menu', description: `Orders, Inventory, Products, Channels, Finance and Reports. ${hasChannels ? '' : 'Start with Channels to connect your first marketplace.'}`, side: 'bottom', align: 'start' },
        },
      ];

  return [
    {
      popover: {
        title: `Welcome to Northstar${firstName ? `, ${firstName}` : ''}!`,
        description: 'Take a one-minute tour of your workspace. You can close it at any time and replay it later.',
      },
    },
    {
      element: '[data-tour="stats"]',
      popover: { title: 'Your business at a glance', description: 'Revenue, orders, profit and inventory value for the last 30 days. The percentages compare with the 30 days before.', side: 'bottom', align: 'start' },
    },
    {
      element: '[data-tour="recent-orders"]',
      popover: { title: 'Recent orders', description: 'The latest orders across all your channels. Click any order to see its items, timeline and profit breakdown.', side: 'top', align: 'start' },
    },
    {
      element: '[data-tour="channel-performance"]',
      popover: { title: 'Channel performance', description: 'Compare how much each marketplace is selling this month.', side: 'top', align: 'end' },
    },
    ...navigation,
    {
      element: '[data-tour="help"]',
      popover: { title: "That's it!", description: 'Click this button whenever you want to replay the tour. Your profile and password are under Settings.', side: 'bottom', align: 'end' },
    },
  ];
}

/**
 * Start the product tour. `onFinish` runs once when it ends, whether the user completes it or closes it early.
 */
export function startTour(options) {
  // Defer to the next task: if a click triggered this, driver.js would otherwise receive that same click
  // as an "outside click" while it's still bubbling and close the tour immediately.
  setTimeout(() => drive(options), 0);
}

function drive({ firstName, hasChannels, onFinish }) {
  if (activeTour?.isActive()) return;

  activeTour = driver({
    steps: buildSteps({ firstName, hasChannels }),
    showProgress: true,
    progressText: '{{current}} of {{total}}',
    nextBtnText: 'Next',
    prevBtnText: 'Back',
    doneBtnText: 'Get started',
    popoverClass: 'northstar-tour',
    overlayOpacity: 0.55,
    stagePadding: 6,
    stageRadius: 12,
    smoothScroll: true,
    onDestroyed: () => {
      activeTour = null;
      onFinish?.();
    },
  });
  activeTour.drive();
}

export function stopTour() {
  activeTour?.destroy();
}
