<?php

namespace App\Services\Shopify;

use App\Models\Channel;
use App\Models\Order;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\OrderService;
use App\Services\PaymentLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pulls a Shopify store's catalog, stock levels and orders into the workspace.
 * Products are matched by Shopify variant ID (then SKU), orders by Shopify order ID, so re-running is safe.
 */
class ShopifySync
{
    private const SHOP_QUERY = '{ shop { name currencyCode myshopifyDomain } }';

    private const VARIANTS_QUERY = <<<'GQL'
        query ($cursor: String) {
          productVariants(first: 100, after: $cursor) {
            pageInfo { hasNextPage endCursor }
            nodes {
              legacyResourceId sku title price inventoryQuantity
              product { title status }
              inventoryItem { unitCost { amount } }
            }
          }
        }
        GQL;

    private const ORDERS_QUERY = <<<'GQL'
        query ($cursor: String, $query: String) {
          orders(first: 25, after: $cursor, query: $query, sortKey: UPDATED_AT) {
            pageInfo { hasNextPage endCursor }
            nodes {
              legacyResourceId name createdAt cancelledAt displayFulfillmentStatus
              %CUSTOMER%
              subtotalPriceSet { shopMoney { amount } }
              totalRefundedSet { shopMoney { amount } }
              fulfillments(first: 3) { displayStatus createdAt deliveredAt }
              lineItems(first: 20) {
                nodes { sku name quantity originalUnitPriceSet { shopMoney { amount } } variant { legacyResourceId } }
              }
            }
          }
        }
        GQL;

    /** Customer fields are "protected customer data"; apps without that access get the orders without them. */
    private const CUSTOMER_FIELDS = 'email customer { displayName email } shippingAddress { name address1 address2 city provinceCode zip country }';

    private const STATUS_EVENTS = [
        'processing' => 'Processing started',
        'shipped' => 'Shipped to customer',
        'delivered' => 'Delivered',
        'cancelled' => 'Order cancelled',
    ];

    private ShopifyClient $client;

    /** Variant ID → product, filled while importing the catalog and used to link order lines. */
    private array $productsByVariant = [];

    public function __construct(private Channel $channel)
    {
        $this->client = new ShopifyClient($channel);
    }

    /** Check the credentials work and return the shop's details. */
    public function shop(): array
    {
        return $this->client->query(self::SHOP_QUERY)['shop'] ?? [];
    }

    /** @return array<int, SyncLog> */
    public function run(): array
    {
        @set_time_limit(0);
        $startedAt = now();

        $shop = $this->shop();
        $this->channel->update(['currency' => $shop['currencyCode'] ?? $this->channel->currency]);

        $logs = [];
        $logs[] = $this->channel->syncLogs()->create(['job_type' => 'Inventory sync', 'records' => $this->syncProducts(), 'status' => 'success']);
        $logs[] = $this->channel->syncLogs()->create(['job_type' => 'Orders sync', 'records' => $this->syncOrders(), 'status' => 'success']);

        $this->channel->update(['status' => 'connected', 'last_synced_at' => $startedAt]);

        return $logs;
    }

    private function syncProducts(): int
    {
        $user = $this->channel->user;
        $listings = $this->channel->listings()->with('product')->get()->keyBy('external_id');
        $usedSkus = [];
        $count = 0;

        foreach ($this->paginate(self::VARIANTS_QUERY, [], 'productVariants') as $variant) {
            if (($variant['product']['status'] ?? null) === 'ARCHIVED') {
                continue;
            }

            $variantId = $variant['legacyResourceId'];
            $externalId = "Variant ID {$variantId}";
            $sku = trim((string) $variant['sku']) ?: "SHOPIFY-{$variantId}";
            if (isset($usedSkus[$sku])) {
                $sku .= "-{$variantId}"; // Shopify allows duplicate SKUs; the inventory needs them unique.
            }
            $usedSkus[$sku] = true;

            $name = $variant['product']['title'].(in_array($variant['title'], ['Default Title', ''], true) ? '' : " / {$variant['title']}");
            $onHand = max(0, (int) $variant['inventoryQuantity']);
            $attributes = [
                'name' => mb_substr($name, 0, 255),
                'price' => (float) $variant['price'],
                'unit_cost' => (float) ($variant['inventoryItem']['unitCost']['amount'] ?? 0),
            ];

            DB::transaction(function () use ($user, $listings, $externalId, $sku, $attributes, $onHand, $variantId) {
                /** @var Product|null $product */
                $product = $listings[$externalId]->product ?? $user->products()->where('sku', $sku)->first();

                if (! $product) {
                    $product = $user->products()->create($attributes + ['sku' => $sku, 'warehouse' => 'Shopify', 'on_hand' => $onHand]);
                    if ($onHand > 0) {
                        $product->movements()->create(['quantity' => $onHand, 'reason' => 'Opening balance', 'note' => 'Imported from Shopify']);
                    }
                } else {
                    $product->update($attributes);
                    if ($product->on_hand !== $onHand) {
                        $product->adjustStock($onHand - $product->on_hand, 'Shopify sync', 'Stock level updated from Shopify');
                    }
                }

                $product->listings()->updateOrCreate(['channel_id' => $this->channel->id], ['external_id' => $externalId, 'allocated' => $onHand]);
                $this->productsByVariant[$variantId] = $product;
            });
            $count++;
        }

        return $count;
    }

    private function syncOrders(): int
    {
        // After the first import only fetch orders changed since the last sync (with an hour of overlap).
        $filter = $this->channel->last_synced_at && $this->channel->orders()->exists()
            ? "updated_at:>'".$this->channel->last_synced_at->copy()->subHour()->toIso8601ZuluString()."'"
            : null;

        $count = 0;
        $withCustomer = true;

        while (true) {
            $query = str_replace('%CUSTOMER%', $withCustomer ? self::CUSTOMER_FIELDS : '', self::ORDERS_QUERY);
            try {
                foreach ($this->paginate($query, ['query' => $filter], 'orders') as $node) {
                    $this->upsertOrder($node);
                    $count++;
                }

                return $count;
            } catch (ShopifyException $e) {
                // Retry once without customer details when the app lacks protected customer data access.
                if (! $withCustomer || ! str_contains(strtolower($e->getMessage()), 'access denied')) {
                    throw $e;
                }
                $withCustomer = false;
                $count = 0;
            }
        }
    }

    private function upsertOrder(array $node): void
    {
        $status = $this->status($node);
        $placedAt = Carbon::parse($node['createdAt']);
        $subtotal = (float) $node['subtotalPriceSet']['shopMoney']['amount'];
        $address = $node['shippingAddress'] ?? null;

        $lines = collect($node['lineItems']['nodes'])->map(function ($line) {
            $product = $this->productFor($line);

            return [
                'product_id' => $product?->id,
                'sku' => trim((string) $line['sku']) ?: ($product?->sku ?? 'SHOPIFY-ITEM'),
                'name' => mb_substr($line['name'], 0, 255),
                'quantity' => (int) $line['quantity'],
                'unit_price' => (float) $line['originalUnitPriceSet']['shopMoney']['amount'],
                'unit_cost' => $product?->unit_cost ?? 0,
            ];
        })->filter(fn ($line) => $line['quantity'] > 0);

        $attributes = [
            'number' => $node['name'],
            'customer_name' => mb_substr($node['customer']['displayName'] ?? $address['name'] ?? $node['email'] ?? 'Shopify customer', 0, 255),
            'customer_email' => $node['customer']['email'] ?? $node['email'] ?? null,
            'shipping_address' => $address ? $this->formatAddress($address) : null,
            'status' => $status,
            'fulfillment' => 'FBM',
            'subtotal' => $subtotal,
            'channel_fees' => round($subtotal * OrderService::FEE_RATES['Shopify'], 2),
            'shipping_cost' => 0,
            'cogs' => round($lines->sum(fn ($l) => $l['quantity'] * $l['unit_cost']), 2),
            'refunds' => min($subtotal, (float) $node['totalRefundedSet']['shopMoney']['amount']),
            'placed_at' => $placedAt,
        ];

        DB::transaction(function () use ($node, $attributes, $lines, $status, $placedAt) {
            /** @var Order|null $order */
            $order = $this->channel->orders()->where('external_id', $node['legacyResourceId'])->first()
                // The same order typed in by hand, or known so far only from payment transactions (keyed by its name).
                ?? $this->channel->orders()->where('number', $node['name'])
                    ->where(fn ($q) => $q->whereNull('external_id')->orWhere('external_id', $node['name']))->first();

            if ($order && $order->external_id === null && in_array($order->status, Order::OPEN_STATUSES, true)) {
                // A manual order held reservations; Shopify's stock levels already account for this order.
                foreach ($order->items()->whereNotNull('product_id')->get() as $item) {
                    Product::whereKey($item->product_id)->where('reserved', '>=', $item->quantity)->decrement('reserved', $item->quantity);
                }
            }

            if (! $order) {
                $order = $this->channel->user->orders()->create($attributes + ['channel_id' => $this->channel->id, 'external_id' => $node['legacyResourceId']]);
                $order->events()->create(['label' => 'Order placed', 'created_at' => $placedAt]);
                $order->events()->create(['label' => 'Imported from Shopify']);
                if ($status !== 'paid') {
                    $order->events()->create(['label' => self::STATUS_EVENTS[$status], 'created_at' => $this->statusTime($node, $status) ?? $placedAt]);
                }
            } else {
                if ($order->status !== $status) {
                    $order->events()->create(['label' => self::STATUS_EVENTS[$status] ?? 'Status updated in Shopify']);
                }
                $order->update($attributes + ['external_id' => $node['legacyResourceId']]);
                $order->items()->delete();
            }

            $order->items()->createMany($lines->all());
            // Actual fees and refunds from payment transactions win over the estimate.
            PaymentLedger::refreshOrderIfPaid($order);
        });
    }

    private function status(array $node): string
    {
        if ($node['cancelledAt']) {
            return 'cancelled';
        }

        return match ($node['displayFulfillmentStatus']) {
            'FULFILLED' => collect($node['fulfillments'])->contains('displayStatus', 'DELIVERED') ? 'delivered' : 'shipped',
            'UNFULFILLED' => 'paid',
            'RESTOCKED' => 'cancelled',
            default => 'processing', // partially fulfilled, in progress, on hold, scheduled…
        };
    }

    private function statusTime(array $node, string $status): ?Carbon
    {
        $time = match ($status) {
            'cancelled' => $node['cancelledAt'],
            'delivered' => collect($node['fulfillments'])->pluck('deliveredAt')->filter()->max(),
            'shipped' => collect($node['fulfillments'])->pluck('createdAt')->filter()->max(),
            default => null,
        };

        return $time ? Carbon::parse($time) : null;
    }

    private function productFor(array $line): ?Product
    {
        $variantId = $line['variant']['legacyResourceId'] ?? null;
        if ($variantId && isset($this->productsByVariant[$variantId])) {
            return $this->productsByVariant[$variantId];
        }

        $sku = trim((string) $line['sku']);

        return $sku !== '' ? $this->channel->user->products()->where('sku', $sku)->first() : null;
    }

    private function formatAddress(array $a): string
    {
        $street = trim(($a['address1'] ?? '').' '.($a['address2'] ?? ''));
        $city = trim(($a['city'] ?? '').', '.trim(($a['provinceCode'] ?? '').' '.($a['zip'] ?? '')), ', ');

        return collect([$street, $city, $a['country'] ?? null])->filter()->implode("\n");
    }

    /** Yield every node of a paginated connection. */
    private function paginate(string $query, array $variables, string $connection): \Generator
    {
        $cursor = null;
        do {
            $page = $this->client->query($query, $variables + ['cursor' => $cursor])[$connection] ?? null;
            if (! $page) {
                return;
            }
            yield from $page['nodes'];
            $cursor = $page['pageInfo']['endCursor'];
        } while ($page['pageInfo']['hasNextPage']);
    }
}
