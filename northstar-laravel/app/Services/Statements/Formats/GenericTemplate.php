<?php

namespace App\Services\Statements\Formats;

/** Northstar's own CSV layout, for any platform or report we don't read directly. One row per order line. */
class GenericTemplate extends StatementFormat
{
    public const HEADERS = ['order_id', 'date', 'sku', 'product_name', 'quantity', 'unit_price', 'fees', 'shipping_cost', 'refund', 'status', 'customer_name', 'customer_email'];

    public function key(): string
    {
        return 'generic';
    }

    public function label(): string
    {
        return 'Northstar template (any platform)';
    }

    public function platform(): ?string
    {
        return null;
    }

    public function hint(): string
    {
        return 'One row per order line. Fees, shipping cost and refund are per order: put them on any one row of that order. Leave fees empty to use an estimate.';
    }

    public function requiredHeaders(): array
    {
        return ['order_id', 'date', 'sku', 'quantity', 'unit_price'];
    }

    public function parse(array $records): array
    {
        $orders = [];
        $warnings = [];
        $statuses = ['paid', 'processing', 'shipped', 'delivered', 'cancelled'];

        foreach ($records as $i => $r) {
            $id = $r['order_id'] ?? '';
            if ($id === '') {
                continue;
            }

            $orders[$id] ??= $this->order($id, ['placed_at' => $this->date($r['date'] ?? '', ['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'm/d/Y'])]);
            $order = &$orders[$id];
            $status = strtolower($r['status'] ?? '');
            if ($status !== '') {
                in_array($status, $statuses, true)
                    ? $order['status'] = $status
                    : $warnings[] = 'Row '.($i + 2).": unknown status \"{$r['status']}\" (use ".implode(', ', $statuses).').';
            }
            $order['customer_name'] ??= ($r['customer_name'] ?? '') ?: null;
            $order['customer_email'] ??= ($r['customer_email'] ?? '') ?: null;
            if (($r['fees'] ?? '') !== '') {
                $order['fees'] = ($order['fees'] ?? 0) + $this->money($r['fees']);
            }
            $order['shipping_cost'] += $this->money($r['shipping_cost'] ?? '');
            $order['refunds'] += $this->money($r['refund'] ?? '');

            $quantity = $this->quantity($r['quantity'] ?? '');
            if ($quantity > 0) {
                $order['lines'][] = [
                    'sku' => $r['sku'] ?? '',
                    'name' => ($r['product_name'] ?? '') ?: ($r['sku'] ?? ''),
                    'quantity' => $quantity,
                    'unit_price' => $this->money($r['unit_price'] ?? ''),
                ];
            }
            unset($order);
        }

        return $this->result($orders, warnings: array_slice($warnings, 0, 20));
    }
}
