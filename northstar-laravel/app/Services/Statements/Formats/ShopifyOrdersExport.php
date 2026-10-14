<?php

namespace App\Services\Statements\Formats;

/** Shopify admin → Orders → Export (one row per line item; order details on the first row). */
class ShopifyOrdersExport extends StatementFormat
{
    public function key(): string
    {
        return 'shopify_orders';
    }

    public function label(): string
    {
        return 'Shopify · Orders export';
    }

    public function platform(): ?string
    {
        return 'Shopify';
    }

    public function hint(): string
    {
        return 'Shopify admin → Orders → Export → "Plain CSV file".';
    }

    public function requiredHeaders(): array
    {
        return ['name', 'created at', 'lineitem quantity', 'lineitem sku'];
    }

    public function parse(array $records): array
    {
        $orders = [];
        $currency = null;

        foreach ($records as $r) {
            $number = $r['name'] ?? '';
            if ($number === '') {
                continue;
            }

            if (! isset($orders[$number])) {
                $orders[$number] = $this->order($number, ['number' => $number]);
            }
            $order = &$orders[$number];

            // Order-level columns are only filled on the order's first row.
            if (($r['financial status'] ?? '') !== '' || $order['placed_at'] === null) {
                $currency ??= $r['currency'] ?? null;
                $fulfillment = strtolower($r['fulfillment status'] ?? '');
                $order = array_merge($order, array_filter([
                    'placed_at' => $this->date($r['created at'] ?? ''),
                    'status' => match (true) {
                        ($r['cancelled at'] ?? '') !== '' => 'cancelled',
                        $fulfillment === 'fulfilled' => 'shipped',
                        $fulfillment === 'partial' => 'processing',
                        default => 'paid',
                    },
                    'customer_name' => $this->value($r, 'shipping name', 'billing name') ?: null,
                    'customer_email' => ($r['email'] ?? '') ?: null,
                    'shipping_address' => $this->address(
                        $this->value($r, 'shipping address1', 'shipping street'), $r['shipping address2'] ?? '',
                        $r['shipping city'] ?? '', $r['shipping province'] ?? '', $r['shipping zip'] ?? '', $r['shipping country'] ?? '',
                    ),
                    'subtotal' => ($r['subtotal'] ?? '') !== '' ? $this->money($r['subtotal']) : null,
                    'refunds' => $this->money($r['refunded amount'] ?? ''),
                ], fn ($v) => $v !== null));
            }

            $quantity = $this->quantity($r['lineitem quantity'] ?? '');
            if ($quantity > 0) {
                $order['lines'][] = [
                    'sku' => $r['lineitem sku'] ?? '',
                    'name' => $r['lineitem name'] ?? '',
                    'quantity' => $quantity,
                    'unit_price' => $this->money($r['lineitem price'] ?? ''),
                ];
            }
            unset($order);
        }

        return $this->result($orders, currency: $currency);
    }
}
