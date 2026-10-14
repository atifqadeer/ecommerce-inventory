<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fills a workspace with realistic sample data: four channels, a catalog, six months of orders,
 * expenses, sync logs and a balanced ledger. All dates are relative to "now".
 */
class DemoWorkspace
{
    private const CHANNELS = [
        // name, handle, status, token expiry (days), sync frequency, last synced (minutes ago), order weight
        ['Shopify', 'northstar-store.myshopify.com', 'connected', 42, 'Every 15 minutes', 2, 40],
        ['Amazon', 'Northstar US Marketplace', 'connected', 18, 'Every 30 minutes', 7, 32],
        ['Etsy', 'Northstar Goods', 'action_needed', -1, 'Hourly', 60, 18],
        ['eBay', 'northstar-commerce', 'connected', 76, 'Hourly', 38, 10],
    ];

    private const PRODUCTS = [
        // sku, name, warehouse, price, cost, on hand, reorder point, [Shopify, Amazon, Etsy, eBay] allocation
        ['NS-TEE-BLK-M', 'Everyday tee / black / M', 'East Coast', 48, 14, 184, 20, [61, 48, 24, 18]],
        ['NS-MUG-CER-WHT', 'Ceramic mug / white', 'West Coast', 24, 7, 92, 20, [30, 26, 9, 6]],
        ['NS-TOTE-NAT', 'Canvas tote / natural', 'East Coast', 52, 15, 38, 20, [5, 15, 7, 3]],
        ['NS-HOOD-GRY-L', 'Heavyweight hoodie / grey / L', 'West Coast', 89, 28, 64, 20, [18, 21, 8, 5]],
        ['NS-CAP-NVY', 'Northstar cap / navy', 'East Coast', 32, 9, 17, 20, [3, 5, 6, 3]],
        ['MN-2048', 'Minimalist Desk Lamp', 'East Coast', 65, 22, 28, 20, [12, 8, 4, 4]],
        ['BK-1002', 'Canvas Weekender Bag', 'West Coast', 78, 26, 37, 25, [10, 14, 6, 7]],
        ['KT-8831', 'Ceramic Pour Over Set', 'West Coast', 55, 18, 46, 30, [14, 10, 16, 6]],
        ['OF-4120', 'Desk Organizer Tray', 'East Coast', 55, 19, 49, 30, [15, 12, 10, 12]],
    ];

    private const CUSTOMERS = [
        'Maya Chen', 'Noah Williams', 'Sofia Rodriguez', 'Liam Patel', 'Olivia Martin', 'James Wilson', 'Mia Thompson',
        'Noah Davis', 'Ava Johnson', 'Ethan Brown', 'Isabella Garcia', 'Lucas Miller', 'Amelia Moore', 'Mason Taylor',
        'Harper Anderson', 'Elijah Thomas', 'Evelyn Jackson', 'Logan White', 'Abigail Harris', 'Henry Clark',
    ];

    private const ADDRESSES = [
        "245 Market Street\nSan Francisco, CA 94105\nUnited States",
        "88 Bedford Avenue\nBrooklyn, NY 11211\nUnited States",
        "1200 Pearl Street\nBoulder, CO 80302\nUnited States",
        "410 Congress Avenue\nAustin, TX 78701\nUnited States",
        "77 Pike Street\nSeattle, WA 98101\nUnited States",
    ];

    public static function seed(User $user): void
    {
        mt_srand($user->id * 7919);

        DB::transaction(function () use ($user) {
            Ledger::setupChartOfAccounts($user);
            $now = CarbonImmutable::now();

            $channels = [];
            $weights = [];
            foreach (self::CHANNELS as [$name, $handle, $status, $expiry, $frequency, $synced, $weight]) {
                $channels[$name] = $user->channels()->create([
                    'platform' => $name,
                    'name' => $name,
                    'handle' => $handle,
                    'color' => Channel::SUPPORTED[$name],
                    'status' => $status,
                    'token_expires_at' => $now->addDays($expiry),
                    'sync_frequency' => $frequency,
                    'last_synced_at' => $now->subMinutes($synced),
                ]);
                $weights[$name] = $weight;
            }

            $products = self::seedProducts($user, $channels, $now);
            $orders = self::seedOrders($user, $channels, $weights, $products, $now);
            self::seedExpenses($user, $channels, $orders, $now);
            self::seedSyncLogs($channels, $now);
            self::seedLedger($user, $channels, $now);
        });
    }

    /** @return array<int, Product> */
    private static function seedProducts(User $user, array $channels, CarbonImmutable $now): array
    {
        $products = [];
        foreach (self::PRODUCTS as [$sku, $name, $warehouse, $price, $cost, $onHand, $reorder, $allocations]) {
            $product = $user->products()->create([
                'sku' => $sku, 'name' => $name, 'warehouse' => $warehouse, 'price' => $price,
                'unit_cost' => $cost, 'on_hand' => $onHand, 'reorder_point' => $reorder,
            ]);

            foreach (array_values($channels) as $i => $channel) {
                $product->listings()->create([
                    'channel_id' => $channel->id,
                    'allocated' => $allocations[$i],
                    'external_id' => match ($channel->name) {
                        'Shopify' => 'Variant ID '.mt_rand(48000000, 48999999),
                        'Amazon' => 'ASIN B0C'.strtoupper(substr(md5($sku), 0, 7)),
                        'Etsy' => 'Listing ID '.mt_rand(12000000, 12999999),
                        default => 'Item ID '.mt_rand(275000000, 275999999),
                    },
                ]);
            }

            $product->movements()->create(['quantity' => $onHand - 24, 'reason' => 'Opening balance imported', 'note' => 'Initial catalog import', 'created_at' => $now->subDays(170)]);
            $product->movements()->create(['quantity' => 24, 'reason' => 'Receiving', 'note' => "{$warehouse} warehouse", 'created_at' => $now->subDays(2)]);
            $products[] = $product;
        }

        return $products;
    }

    /** @return array<int, Order> */
    private static function seedOrders(User $user, array $channels, array $weights, array $products, CarbonImmutable $now): array
    {
        $orders = [];
        $sequence = 10001;

        for ($daysAgo = 180; $daysAgo >= 0; $daysAgo--) {
            // Volume grows over time so the dashboard shows healthy period-over-period growth.
            $count = mt_rand(0, 1 + intdiv(180 - $daysAgo, 50));
            for ($n = 0; $n < $count; $n++) {
                $channelName = self::weightedPick($weights);
                $channel = $channels[$channelName];
                $placedAt = $now->subDays($daysAgo)->setTime(mt_rand(7, 21), mt_rand(0, 59));
                if ($placedAt->isFuture()) {
                    $placedAt = $now->subMinutes(mt_rand(5, 300));
                }

                $status = match (true) {
                    $daysAgo <= 1 => mt_rand(0, 1) ? 'paid' : 'processing',
                    $daysAgo <= 3 => ['paid', 'processing', 'shipped'][mt_rand(0, 2)],
                    mt_rand(1, 30) === 1 => 'cancelled',
                    $daysAgo <= 7 => 'shipped',
                    default => 'delivered',
                };
                $fba = $channelName === 'Amazon' && mt_rand(0, 1);

                $picked = [];
                foreach ((array) array_rand($products, mt_rand(1, 3)) as $index) {
                    $picked[] = [$products[$index], mt_rand(1, 3)];
                }
                $subtotal = array_sum(array_map(fn ($p) => $p[0]->price * $p[1], $picked));
                $cogs = array_sum(array_map(fn ($p) => $p[0]->unit_cost * $p[1], $picked));

                $order = $user->orders()->create([
                    'channel_id' => $channel->id,
                    'number' => '#'.OrderService::NUMBER_PREFIXES[$channelName].'-'.$sequence++,
                    'customer_name' => $customer = self::CUSTOMERS[array_rand(self::CUSTOMERS)],
                    'customer_email' => strtolower(str_replace(' ', '.', $customer)).'@example.com',
                    'shipping_address' => self::ADDRESSES[array_rand(self::ADDRESSES)],
                    'status' => $status,
                    'fulfillment' => $fba ? 'FBA' : 'FBM',
                    'subtotal' => $subtotal,
                    'channel_fees' => round($subtotal * OrderService::FEE_RATES[$channelName], 2),
                    'shipping_cost' => $fba ? 4.5 : mt_rand(600, 1200) / 100,
                    'cogs' => $cogs,
                    'refunds' => $status === 'delivered' && mt_rand(1, 25) === 1 ? $picked[0][0]->price : 0,
                    'placed_at' => $placedAt,
                ]);

                foreach ($picked as [$product, $quantity]) {
                    $order->items()->create([
                        'product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name,
                        'quantity' => $quantity, 'unit_price' => $product->price, 'unit_cost' => $product->unit_cost,
                    ]);
                    if (in_array($status, Order::OPEN_STATUSES, true)) {
                        $product->increment('reserved', $quantity);
                    }
                }

                self::seedOrderEvents($order, $placedAt);
                $orders[] = $order;
            }
        }

        return $orders;
    }

    private static function seedOrderEvents(Order $order, CarbonImmutable $placedAt): void
    {
        $events = [['Order placed', $placedAt], ['Payment captured', $placedAt->addMinute()]];
        $reached = fn (string $status) => array_search($order->status, Order::STATUSES) >= array_search($status, Order::STATUSES);

        if ($order->status === 'cancelled') {
            $events[] = ['Order cancelled', $placedAt->addHours(3)];
        } else {
            if ($reached('processing')) {
                $events[] = ['Processing started', $placedAt->addHours(2)];
            }
            if ($reached('shipped')) {
                $events[] = ['Shipped to customer', $placedAt->addDay()];
            }
            if ($reached('delivered')) {
                $events[] = ['Delivered', $placedAt->addDays(4)];
            }
            if ($order->refunds > 0) {
                $events[] = ['Refund issued · $'.number_format($order->refunds, 2), $placedAt->addDays(9)];
            }
        }

        foreach ($events as [$label, $at]) {
            $order->events()->create(['label' => $label, 'created_at' => $at]);
        }
    }

    private static function seedExpenses(User $user, array $channels, array $orders, CarbonImmutable $now): void
    {
        $revenue = [];
        foreach ($orders as $order) {
            if ($order->status !== 'cancelled') {
                $key = $order->placed_at->format('Y-m').'|'.$order->channel_id;
                $revenue[$key] = ($revenue[$key] ?? 0) + $order->subtotal;
            }
        }

        for ($m = 5; $m >= 0; $m--) {
            $month = $now->subMonthsNoOverflow($m)->startOfMonth();
            foreach ($channels as $channel) {
                $sales = $revenue[$month->format('Y-m').'|'.$channel->id] ?? 0;
                if ($sales <= 0) {
                    continue;
                }
                $user->expenses()->create([
                    'channel_id' => $channel->id, 'category' => 'advertising', 'incurred_on' => $month->addDays(14),
                    'description' => "{$channel->name} sponsored listings", 'amount' => round($sales * mt_rand(50, 80) / 1000, 2),
                ]);
                if (in_array($channel->name, ['Shopify', 'Etsy'], true)) {
                    $user->expenses()->create([
                        'channel_id' => $channel->id, 'category' => 'payment_processing', 'incurred_on' => $month->addDays(27),
                        'description' => "{$channel->name} payment processing", 'amount' => round($sales * 0.029, 2),
                    ]);
                }
            }
            $user->expenses()->create([
                'channel_id' => null, 'category' => 'software', 'incurred_on' => $month,
                'description' => 'Northstar, accounting & email tools', 'amount' => 389,
            ]);
        }
    }

    private static function seedSyncLogs(array $channels, CarbonImmutable $now): void
    {
        foreach ($channels as $channel) {
            for ($i = 6; $i >= 0; $i--) {
                $failed = $channel->status === 'action_needed' && $i < 3;
                $channel->syncLogs()->create([
                    'job_type' => ['Orders sync', 'Inventory sync', 'Listings sync'][$i % 3],
                    'records' => $failed ? null : mt_rand(12, 1400),
                    'status' => $failed ? 'failed' : 'success',
                    'error' => $failed ? 'Token expired' : null,
                    'created_at' => $now->subMinutes($i * 47 + mt_rand(1, 20)),
                ]);
            }
        }
    }

    private static function seedLedger(User $user, array $channels, CarbonImmutable $now): void
    {
        $code = fn (string $c) => Ledger::account($user, $c)->id;
        $receivable = fn (string $name) => Ledger::receivableAccountFor($channels[$name])->id;
        $date = fn (int $daysAgo) => $now->subDays($daysAgo)->toDateString();

        Ledger::post($user, $date(180), 'Opening balances', [
            ['ledger_account_id' => $code('1000'), 'debit' => 30000],
            ['ledger_account_id' => $receivable('Shopify'), 'debit' => 4200],
            ['ledger_account_id' => $receivable('Amazon'), 'debit' => 6100],
            ['ledger_account_id' => $receivable('Etsy'), 'debit' => 980],
            ['ledger_account_id' => $receivable('eBay'), 'debit' => 1750],
            ['ledger_account_id' => $code('1200'), 'debit' => 21460],
            ['ledger_account_id' => $code('1500'), 'debit' => 1260],
            ['ledger_account_id' => $code('2000'), 'credit' => 6840],
            ['ledger_account_id' => $code('2100'), 'credit' => 1836],
            ['ledger_account_id' => $code('3000'), 'credit' => 42000],
            ['ledger_account_id' => $code('3100'), 'credit' => 15074],
        ]);
        Ledger::post($user, $date(15), 'Marketplace sales recognised', [
            ['ledger_account_id' => $receivable('Shopify'), 'debit' => 5400],
            ['ledger_account_id' => $receivable('Amazon'), 'debit' => 4300],
            ['ledger_account_id' => $receivable('Etsy'), 'debit' => 900],
            ['ledger_account_id' => $receivable('eBay'), 'debit' => 600],
            ['ledger_account_id' => $code('3100'), 'credit' => 11200],
        ]);
        Ledger::post($user, $date(10), 'Shopify payout', [
            ['ledger_account_id' => $code('1000'), 'debit' => 3100],
            ['ledger_account_id' => $receivable('Shopify'), 'credit' => 3100],
        ]);
        Ledger::post($user, $date(5), 'Marketplace settlement batch', [
            ['ledger_account_id' => $code('1000'), 'debit' => 4820],
            ['ledger_account_id' => $receivable('Amazon'), 'credit' => 4820],
        ]);
        Ledger::post($user, $date(3), 'Inventory receipt · PO-8842', [
            ['ledger_account_id' => $code('1200'), 'debit' => 1864],
            ['ledger_account_id' => $code('2000'), 'credit' => 1864],
        ]);
        Ledger::post($user, $date(1), 'Supplier invoice · SUP-2201', [
            ['ledger_account_id' => $code('2000'), 'debit' => 1280],
            ['ledger_account_id' => $code('1000'), 'credit' => 1280],
        ]);
    }

    private static function weightedPick(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }
}
