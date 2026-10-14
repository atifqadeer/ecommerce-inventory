<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Order lifecycle: placing an order reserves stock, shipping consumes it, cancelling releases it.
 */
class OrderService
{
    /** Marketplace commission charged on the order subtotal. */
    public const FEE_RATES = ['Shopify' => 0.06, 'Amazon' => 0.15, 'Etsy' => 0.095, 'eBay' => 0.10];

    public const NUMBER_PREFIXES = ['Shopify' => 'NS', 'Amazon' => 'AMZ', 'Etsy' => 'ET', 'eBay' => 'EB'];

    private const EVENT_LABELS = [
        'processing' => 'Processing started',
        'shipped' => 'Shipped to customer',
        'delivered' => 'Delivered',
        'cancelled' => 'Order cancelled',
    ];

    public static function create(User $user, array $data): Order
    {
        return DB::transaction(function () use ($user, $data) {
            /** @var Channel $channel */
            $channel = $user->channels()->findOrFail($data['channel_id']);

            $lines = [];
            foreach ($data['items'] as $i => $item) {
                /** @var Product $product */
                $product = $user->products()->lockForUpdate()->findOrFail($item['product_id']);
                if ($product->available < $item['quantity']) {
                    throw ValidationException::withMessages([
                        "items.$i.quantity" => "Only {$product->available} units of {$product->sku} are available.",
                    ]);
                }
                $lines[] = [$product, (int) $item['quantity'], (float) ($item['unit_price'] ?? $product->price)];
            }

            $subtotal = array_sum(array_map(fn ($l) => $l[1] * $l[2], $lines));
            $cogs = array_sum(array_map(fn ($l) => $l[1] * $l[0]->unit_cost, $lines));

            $order = $user->orders()->create([
                'channel_id' => $channel->id,
                // The platform's own order number when given, so a later import of the same order matches it.
                'number' => $data['number'] ?? self::nextNumber($user, $channel),
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'status' => 'paid',
                'fulfillment' => $data['fulfillment'] ?? 'FBM',
                'subtotal' => round($subtotal, 2),
                'channel_fees' => round($subtotal * (self::FEE_RATES[$channel->platform] ?? 0.1), 2),
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'cogs' => round($cogs, 2),
                'placed_at' => now(),
            ]);

            foreach ($lines as [$product, $quantity, $price]) {
                $order->items()->create([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'unit_cost' => $product->unit_cost,
                ]);
                $product->increment('reserved', $quantity);
            }

            $order->events()->create(['label' => 'Order placed']);
            $order->events()->create(['label' => 'Payment captured']);

            return $order;
        });
    }

    public static function transition(Order $order, string $status): Order
    {
        if (! in_array($status, Order::TRANSITIONS[$order->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "An order that is {$order->status} cannot be marked {$status}."]);
        }

        return DB::transaction(function () use ($order, $status) {
            // Orders imported from statements already took their units off on-hand stock, so they never hold reservations.
            $releasesStock = ! $order->stock_deducted && in_array($order->status, Order::OPEN_STATUSES, true) && in_array($status, ['shipped', 'cancelled'], true);

            if ($releasesStock) {
                foreach ($order->items()->with('product')->get() as $item) {
                    if (! $item->product) {
                        continue;
                    }
                    $item->product->reserved = max(0, $item->product->reserved - $item->quantity);
                    if ($status === 'shipped') {
                        $item->product->adjustStock(-$item->quantity, 'Order shipped', $order->number);
                    } else {
                        $item->product->save();
                    }
                }
            }

            $order->update(['status' => $status]);
            $order->events()->create(['label' => self::EVENT_LABELS[$status]]);

            return $order;
        });
    }

    public static function refund(Order $order, float $amount): Order
    {
        $refundable = round($order->subtotal - $order->refunds, 2);
        if ($amount > $refundable) {
            throw ValidationException::withMessages(['amount' => "At most \${$refundable} can still be refunded."]);
        }

        $order->update(['refunds' => round($order->refunds + $amount, 2)]);
        $order->events()->create(['label' => 'Refund issued · $'.number_format($amount, 2)]);

        return $order;
    }

    public static function nextNumber(User $user, Channel $channel): string
    {
        $prefix = self::NUMBER_PREFIXES[$channel->platform] ?? strtoupper(substr($channel->platform, 0, 3));

        return "#{$prefix}-".(10001 + $user->orders()->count());
    }
}
