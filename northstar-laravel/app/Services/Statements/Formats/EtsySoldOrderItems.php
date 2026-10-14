<?php

namespace App\Services\Statements\Formats;

/** Etsy Shop Manager → Settings → Options → Download Data → "Order Items" CSV (EtsySoldOrderItems). */
class EtsySoldOrderItems extends StatementFormat
{
    public function key(): string
    {
        return 'etsy_order_items';
    }

    public function label(): string
    {
        return 'Etsy · Sold order items';
    }

    public function platform(): ?string
    {
        return 'Etsy';
    }

    public function hint(): string
    {
        return 'Shop Manager → Settings → Options → Download Data → Type "Order Items". Fees are estimated.';
    }

    public function requiredHeaders(): array
    {
        return ['sale date', 'item name', 'quantity', 'price', 'order id'];
    }

    public function parse(array $records): array
    {
        $orders = [];
        $currency = null;
        $formats = ['m/d/y', 'm/d/Y', 'Y-m-d'];

        foreach ($records as $r) {
            $id = $r['order id'] ?? '';
            if ($id === '') {
                continue;
            }
            $currency ??= ($r['currency'] ?? '') ?: null;

            $orders[$id] ??= $this->order($id, [
                'placed_at' => $this->date($r['sale date'] ?? '', $formats),
                'status' => $this->value($r, 'date shipped', 'date posted') !== '' ? 'shipped' : 'paid',
                'customer_name' => $this->value($r, 'ship name', 'buyer') ?: null,
                'shipping_address' => $this->address(
                    $r['ship address1'] ?? '', $r['ship address2'] ?? '', $r['ship city'] ?? '',
                    $r['ship state'] ?? '', $r['ship zipcode'] ?? '', $r['ship country'] ?? '',
                ),
            ]);

            $quantity = $this->quantity($r['quantity'] ?? '');
            if ($quantity > 0) {
                $total = ($r['item total'] ?? '') !== '' ? $this->money($r['item total']) : $this->money($r['price'] ?? '') * $quantity;
                $variation = ($r['variations'] ?? '') !== '' ? " / {$r['variations']}" : '';
                $orders[$id]['lines'][] = [
                    'sku' => $r['sku'] ?? '',
                    'name' => ($r['item name'] ?? '').$variation,
                    'quantity' => $quantity,
                    'unit_price' => round($total / $quantity, 2),
                ];
            }
        }

        return $this->result($orders, currency: $currency);
    }
}
