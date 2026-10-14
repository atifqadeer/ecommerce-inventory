export const channels = [
  { name: 'Shopify', color: '#78c091', revenue: 4824000, orders: 842, fees: 284600, margin: '34.8%', status: 'synced' },
  { name: 'Amazon', color: '#f59e0b', revenue: 3218000, orders: 521, fees: 418300, margin: '27.2%', status: 'synced' },
  { name: 'Etsy', color: '#ee6b47', revenue: 1842000, orders: 308, fees: 184200, margin: '29.6%', status: 'warning' },
  { name: 'eBay', color: '#4f8cff', revenue: 928000, orders: 167, fees: 92800, margin: '31.4%', status: 'error' },
] as const

export const stats = [
  { label: 'Revenue', value: '$10,812', change: '+12.8%', trend: 'up', color: 'indigo' },
  { label: 'Orders', value: '1,838', change: '+8.4%', trend: 'up', color: 'blue' },
  { label: 'Units sold', value: '2,491', change: '+14.2%', trend: 'up', color: 'violet' },
  { label: 'Gross profit', value: '$3,402', change: '+9.1%', trend: 'up', color: 'emerald' },
  { label: 'Net profit', value: '$2,184', change: '+18.6%', trend: 'up', color: 'amber' },
  { label: 'Inventory value', value: '$42,680', change: '-3.2%', trend: 'down', color: 'rose' },
] as const

export const revenueSeries = [420, 510, 470, 640, 590, 720, 680, 810, 760, 920, 860, 1080]
export const profitSeries = [180, 220, 205, 280, 260, 320, 305, 360, 348, 410, 390, 480]

export const lowStock = [
  { sku: 'MN-2048', product: 'Minimalist Desk Lamp', stock: 8, reorder: 20, channel: 'Shopify' },
  { sku: 'BK-1002', product: 'Canvas Weekender Bag', stock: 12, reorder: 25, channel: 'Amazon' },
  { sku: 'KT-8831', product: 'Ceramic Pour Over Set', stock: 16, reorder: 30, channel: 'Etsy' },
  { sku: 'OF-4120', product: 'Desk Organizer Tray', stock: 19, reorder: 30, channel: 'eBay' },
]

export const topProducts = [
  { sku: 'MN-2048', product: 'Minimalist Desk Lamp', units: 284, revenue: '$18,420', profit: '$6,112' },
  { sku: 'BK-1002', product: 'Canvas Weekender Bag', units: 192, revenue: '$14,880', profit: '$4,920' },
  { sku: 'KT-8831', product: 'Ceramic Pour Over Set', units: 168, revenue: '$9,240', profit: '$3,110' },
  { sku: 'OF-4120', product: 'Desk Organizer Tray', units: 143, revenue: '$7,860', profit: '$2,740' },
]

export const recentOrders = [
  { channel: 'Shopify', id: '#SHP-48291', customer: 'Olivia Martin', total: '$184.00', status: 'Paid', date: 'Today, 10:42 AM' },
  { channel: 'Amazon', id: '#AMZ-88210', customer: 'James Wilson', total: '$92.50', status: 'Shipped', date: 'Today, 9:18 AM' },
  { channel: 'Etsy', id: '#ETS-19044', customer: 'Mia Thompson', total: '$68.00', status: 'Processing', date: 'Yesterday, 6:32 PM' },
  { channel: 'eBay', id: '#EBY-77128', customer: 'Noah Davis', total: '$124.99', status: 'Paid', date: 'Yesterday, 4:05 PM' },
]

export const money = (cents: number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(cents / 100)
