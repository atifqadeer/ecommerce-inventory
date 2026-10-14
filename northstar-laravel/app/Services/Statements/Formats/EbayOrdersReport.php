<?php

namespace App\Services\Statements\Formats;

/**
 * eBay Seller Hub → Orders → Download report. Multi-item orders have an order row followed by
 * one row per item; single-item orders are one row.
 */
class EbayOrdersReport extends StatementFormat
{
    private const DATES = ['M-d-y', 'M-d-Y', 'M d, Y', 'd-M-y', 'Y-m-d'];

    public function key(): string
    {
        return 'ebay_orders';
    }

    public function label(): string
    {
        return 'eBay · Orders report';
    }

    public function platform(): ?string
    {
        return 'eBay';
    }

    public function hint(): string
    {
        return 'Seller Hub → Orders → All orders → Download report (CSV). Fees are estimated; upload the transaction report for actual fees.';
    }

    public function requiredHeaders(): array
    {
        return ['order number', 'item title', 'quantity', 'sold for', 'sale date'];
    }

    public function parse(array $records): array
    {
        $orders = [];

        foreach ($records as $r) {
            $id = $r['order number'] ?? '';
            if ($id === '') {
                continue; // footer lines such as "12 record(s) downloaded"
            }

            $orders[$id] ??= $this->order($id);
            $order = &$orders[$id];
            $order['placed_at'] ??= $this->date($r['sale date'] ?? '', self::DATES);
            $order['customer_name'] ??= $this->value($r, 'ship to name', 'buyer name', 'buyer username') ?: null;
            $order['customer_email'] ??= ($r['buyer email'] ?? '') ?: null;
            $order['shipping_address'] ??= $this->address(
                $r['ship to address 1'] ?? '', $r['ship to address 2'] ?? '', $r['ship to city'] ?? '',
                $r['ship to state'] ?? '', $r['ship to zip'] ?? '', $r['ship to country'] ?? '',
            );
            if (($r['shipped on date'] ?? '') !== '') {
                $order['status'] = 'shipped';
            } elseif (! isset($order['seen'])) {
                $order['status'] = 'paid';
            }
            $order['seen'] = true;

            $quantity = $this->quantity($r['quantity'] ?? '');
            if (($r['item title'] ?? '') !== '' && $quantity > 0) {
                $order['lines'][] = [
                    'sku' => $r['custom label'] ?? '',
                    'name' => $r['item title'],
                    'quantity' => $quantity,
                    'unit_price' => $this->money($r['sold for'] ?? ''),
                ];
            }
            unset($order);
        }

        return $this->result(array_map(fn ($o) => array_diff_key($o, ['seen' => 1]), $orders));
    }
}
