# Northstar — Commerce operations dashboard

Laravel 12 + Vue 3 version of the Northstar Next.js dashboard: manage orders, inventory, channels and finances
(P&L and balance sheet) across Shopify, Amazon, Etsy and eBay.

- **Backend:** Laravel 12 JSON API (`/api/*`), Sanctum bearer-token auth, MySQL/MariaDB database `e-inventory`
- **Frontend:** Vue 3 SPA (`resources/js`) with Vue Router, Pinia, Tailwind CSS v4 and lucide icons, built by Vite

## Getting started

1. Start **MySQL** in the XAMPP Control Panel.
2. Create the database (skip if it already exists). Use phpMyAdmin, or run:

   ```bash
   C:/xampp/mysql/bin/mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS \`e-inventory\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```

3. Install and set up:

   ```bash
   composer install
   npm install
   cp .env.example .env          # skip if .env already exists
   php artisan key:generate
   php artisan migrate --seed    # creates all tables in e-inventory + the demo account with sample data
   npm start                     # Laravel on http://127.0.0.1:8010 + Vite dev server (same as `composer dev`)
   ```

   Run these inside the `northstar-laravel` folder, not the parent `ecommerce` folder (that holds the old Next.js
   project). In VS Code, open this folder (**File → Open Folder…**), then **Terminal → Run Build Task…**
   (`Ctrl+Shift+B`) to start the app.

Open http://127.0.0.1:8010 and sign in with the demo account:

| Email                 | Password   |
| --------------------- | ---------- |
| `demo@northstar.test` | `password` |

Or create your own account at `/register`. Tick **Load sample data** to get a populated workspace, or untick it to start empty.

To serve without the Vite dev server, run `npm run build` once and then `php artisan serve --port=8010`.

### Product tour

New users get a guided tour of the dashboard (built with [driver.js](https://driverjs.com)) the first time they open
the Overview page. Finishing or closing it sets `users.tour_completed_at` (`POST /api/auth/tour`), so it won't start
again, even on another device. Users can replay it from the **?** button in the header or from **Settings → Replay tour**.

- Steps and wording: `resources/js/lib/tour.js`. Steps point at elements with a `data-tour="…"` attribute.
- Phones get a shorter tour that points at the menu button instead of each sidebar link.
- Styling: the `.northstar-tour` rules at the end of `resources/css/app.css` (follows light/dark mode).
- To show the tour again for an existing account:
  `php artisan tinker --execute="App\Models\User::where('email','demo@northstar.test')->update(['tour_completed_at'=>null]);"`

### Database

The app uses the `e-inventory` database on XAMPP's MySQL server (MariaDB 10.4). Settings in `.env`:

```
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=e-inventory
DB_USERNAME=root
DB_PASSWORD=
```

`DB_CONNECTION=mariadb` is Laravel's driver for MariaDB, which is what XAMPP ships as "MySQL". If you move to Oracle
MySQL 8 (e.g. on a production server), set `DB_CONNECTION=mysql`; nothing else changes.

Tables: `users`, `personal_access_tokens`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs`, `channels`, `products`, `channel_listings`, `stock_movements`, `orders`, `order_items`,
`order_events`, `sync_logs`, `expenses`, `ledger_accounts`, `journal_entries`, `journal_lines`.

- Reset everything and reload the demo data: `php artisan migrate:fresh --seed` (**deletes all data**)
- Add new tables later: `php artisan make:migration create_xxx_table`, then `php artisan migrate`

### Password reset emails

`MAIL_MAILER=log` is set by default, so reset emails are written to `storage/logs/laravel.log`. The link in the
email opens the Vue `/reset-password` page. Configure SMTP in `.env` to send real emails.

## Tests

```bash
php artisan test
```

Covers sign-up, login, logout, password reset and profile updates, the order and stock lifecycle, journal-entry balancing, and keeping each user's data separate.

Tests run in an in-memory SQLite database (see `phpunit.xml`), so they never touch the `e-inventory` data.

## Project layout

```
app/
  Http/Controllers/Api/   AuthController, DashboardController, OrderController, ProductController,
                          ChannelController, FinanceController, ExpenseController
  Models/                 User, Channel, Product, ChannelListing, StockMovement, Order, OrderItem, OrderEvent,
                          SyncLog, Expense, LedgerAccount, JournalEntry, JournalLine
  Services/               OrderService (reserve/ship/cancel/refund), ProfitLoss, Ledger (double-entry),
                          DemoWorkspace (sample data), Csv
routes/api.php            all API routes
routes/web.php            catch-all that serves the SPA
resources/js/
  router/  stores/  lib/  layouts/  components/
  pages/                  Overview, orders/, Inventory, Products, Channels, finance/, Reports, Settings, auth/
```

## API

All endpoints except the public auth routes need an `Authorization: Bearer <token>` header. Every query is scoped to the signed-in user.

| Method | Endpoint | Purpose |
| ------ | -------- | ------- |
| POST | `/api/auth/register` | Sign up (`name, email, password, password_confirmation, company?, sample_data?`) → `{ user, token }` |
| POST | `/api/auth/login` | Sign in (`email, password`) → `{ user, token }` |
| POST | `/api/auth/forgot-password` | Email a reset link |
| POST | `/api/auth/reset-password` | Reset with `token, email, password, password_confirmation` |
| GET | `/api/auth/me` | Current user |
| POST | `/api/auth/logout` | Revoke the current token |
| PUT | `/api/auth/profile` | Update name, email, company |
| PUT | `/api/auth/password` | Change password (signs out other devices) |
| GET | `/api/dashboard` | KPIs (last 30 days vs previous 30), recent orders, channel performance, low stock, top products |
| GET | `/api/counts` · `/api/meta` | Sidebar badges · dropdown option lists |
| GET | `/api/orders` | List orders (`status, channel_id, fulfillment, date, search, page`) |
| POST | `/api/orders` | Create an order (reserves stock) |
| GET | `/api/orders/{id}` | Order with items and timeline |
| PATCH | `/api/orders/{id}/status` | `processing`, `shipped` (consumes stock), `delivered`, `cancelled` (releases stock) |
| POST | `/api/orders/{id}/refund` | Record a refund |
| GET | `/api/orders/export` | CSV export |
| GET/POST | `/api/products` | List (`warehouse, channel_id, low_stock, search`) / create |
| GET/PUT/DELETE | `/api/products/{id}` | Show with movements & listings / update / delete |
| POST | `/api/products/adjust` | Bulk stock adjustment (`product_ids[], reason, quantity, note?`) |
| GET | `/api/products/export` | Inventory CSV |
| GET/POST | `/api/channels` | List / connect (or reconnect) a marketplace |
| PATCH | `/api/channels/{id}` | Update handle or sync frequency |
| POST | `/api/channels/{id}/sync` · `/reauthorize` · `/disconnect` | Run sync · refresh token · disconnect |
| GET | `/api/channels/logs` | Sync job log |
| GET | `/api/finance/profit-loss` | P&L (`period`: this_month, last_month, last_3_months, last_6_months, year_to_date; `group`: channel, month, sku) |
| GET | `/api/finance/profit-loss/export` | P&L CSV |
| GET | `/api/finance/balance-sheet?as_of=YYYY-MM-DD` | Balance sheet from the ledger |
| GET | `/api/finance/accounts` · `/accounts/{id}/entries` | Chart of accounts · journal drill-down |
| GET/POST | `/api/finance/journal-entries` | List / post a balanced journal entry |
| GET/POST/DELETE | `/api/expenses` | Operating expenses (advertising, payment processing, software) |

Channel sync is simulated: it records sync-log entries and refreshes timestamps. To connect real marketplaces, call
their APIs from `ChannelController::runSync()`.
