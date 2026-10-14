<?php

namespace App\Services\Statements\Formats;

/** Amazon Seller Central → Reports → Fulfillment → "All Orders" report (tab-separated, one row per item). */
class AmazonOrdersReport extends StatementFormat
{
    public function key(): string
    {
        return 'amazon_orders';
    }

    public function label(): string
    {
        return 'Amazon · All Orders report';
    }

    public function platform(): ?string
    {
        return 'Amazon';
    }

    public function hint(): string
    {
        return 'Seller Central → Reports → Fulfillment → All Orders → Download (.txt). Fees are estimated; upload the transaction report for actual fees.';
    }

    public function requiredHeaders(): array
    {
        return ['amazon-order-id', 'purchase-date', 'sku', 'quantity', 'item-price'];
    }

    public function parse(array $records): array
    {
        $orders = [];
        $currency = null;
        $mcf = [];

        foreach ($records as $r) {
            $id = $r['amazon-order-id'] ?? '';
            if ($id === '') {
                continue;
            }
            $currency ??= ($r['currency'] ?? '') ?: null;

            // Multi-Channel Fulfillment (S01-…, sales channel "Non-Amazon"): Amazon shipped an order sold elsewhere.
            // The units left FBA stock, but the sale belongs to the other channel, so it carries no Amazon sales.
            $isMcf = str_starts_with($id, 'S01-') || str_starts_with(strtolower($r['sales-channel'] ?? ''), 'non-amazon');
            if ($isMcf) {
                $mcf[$id] = true;
            }

            $orders[$id] ??= $this->order($id, [
                'customer_name' => $isMcf ? mb_substr('Multi-Channel Fulfillment '.($r['merchant-order-id'] ?? ''), 0, 255) : null,
                'placed_at' => $this->date($r['purchase-date'] ?? ''),
                'status' => match (strtolower($r['order-status'] ?? '')) {
                    'cancelled' => 'cancelled',
                    'shipped', 'shipped - delivered to buyer' => 'shipped',
                    'shipping' => 'processing',
                    default => 'paid',
                },
                'fulfillment' => strtolower($r['fulfillment-channel'] ?? '') === 'amazon' ? 'FBA' : 'FBM',
                'shipping_address' => $this->address('', '', $r['ship-city'] ?? '', $r['ship-state'] ?? '', $r['ship-postal-code'] ?? '', $r['ship-country'] ?? ''),
            ]);

            $quantity = $this->quantity($r['quantity'] ?? '');
            if ($quantity > 0) {
                $orders[$id]['lines'][] = [
                    'sku' => $r['sku'] ?? '',
                    'name' => $r['product-name'] ?? '',
                    'quantity' => $quantity,
                    // item-price is the line total.
                    'unit_price' => $isMcf ? 0.0 : round(($this->money($r['item-price'] ?? '') - $this->money($r['item-promotion-discount'] ?? '')) / $quantity, 2),
                ];
            }
        }

        foreach (array_keys($mcf) as $id) {
            $orders[$id]['fees'] = 0.0; // no Amazon sale, so no estimated Amazon fee; the fulfilment fee is in the payments report
        }
        $warnings = $mcf ? [count($mcf).' Multi-Channel Fulfillment order(s) (Amazon shipping orders sold on another channel) were recorded with no sales; their units were taken off stock.'] : [];

        return $this->result($orders, currency: $currency, warnings: $warnings);
    }
}
