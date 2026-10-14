<?php

namespace App\Services\Statements\Formats;

/**
 * Amazon Seller Central → Payments → Reports Repository → "Transaction" date range report.
 * One row per transaction: orders and refunds carry sales and fees; other rows (subscription,
 * storage, advertising, adjustments) become expenses. Payout transfers are ignored.
 */
class AmazonTransactionReport extends StatementFormat
{
    private const FEE_COLUMNS = ['selling fees', 'fba fees', 'other transaction fees', 'other'];

    private const REVENUE_COLUMNS = ['product sales', 'shipping credits', 'postage credits', 'gift wrap credits', 'promotional rebates'];

    public function key(): string
    {
        return 'amazon_transactions';
    }

    public function label(): string
    {
        return 'Amazon · Date range transaction report';
    }

    public function platform(): ?string
    {
        return 'Amazon';
    }

    public function hint(): string
    {
        return 'Seller Central → Payments → Reports Repository → Report type "Transaction" → Download CSV.';
    }

    public function requiredHeaders(): array
    {
        return ['date/time', 'type', 'order id', 'sku', 'product sales', 'total'];
    }

    public function parse(array $records): array
    {
        $orders = [];
        $expenses = [];
        $warnings = [];

        foreach ($records as $r) {
            $type = strtolower($r['type'] ?? '');
            $orderId = $r['order id'] ?? '';
            $date = $this->date($r['date/time'] ?? '');
            if (! $date) {
                continue; // summary or footer line
            }

            $revenue = array_sum(array_map(fn ($c) => $this->money($r[$c] ?? ''), self::REVENUE_COLUMNS));
            $fees = -array_sum(array_map(fn ($c) => $this->money($r[$c] ?? ''), self::FEE_COLUMNS));

            if (in_array($type, ['order', 'refund'], true) && $orderId !== '') {
                $orders[$orderId] ??= $this->order($orderId, ['placed_at' => $date, 'subtotal' => 0.0, 'fees' => 0.0, 'partial' => true]);
                $order = &$orders[$orderId];
                $order['fees'] += $fees;

                if ($type === 'order') {
                    $order['partial'] = false;
                    $order['placed_at'] = min($order['placed_at'], $date);
                    $order['subtotal'] += $revenue;
                    $order['fulfillment'] = str_starts_with(strtolower($this->value($r, 'fulfillment', 'fulfilment')), 'amazon') ? 'FBA' : 'FBM';
                    $order['shipping_address'] ??= $this->address('', '', $r['order city'] ?? '', $r['order state'] ?? '', $r['order postal'] ?? '', '');
                    $quantity = $this->quantity($r['quantity'] ?? '');
                    if ($quantity > 0) {
                        $order['lines'][] = [
                            'sku' => $r['sku'] ?? '',
                            'name' => $r['description'] ?? '',
                            'quantity' => $quantity,
                            'unit_price' => round(($this->money($r['product sales'] ?? '') + $this->money($r['promotional rebates'] ?? '')) / $quantity, 2),
                        ];
                    }
                } else {
                    $order['refunds'] += -$revenue;
                }
                unset($order);

                continue;
            }

            $total = $this->money($r['total'] ?? '');
            if ($type === 'transfer' || $total == 0.0) {
                continue;
            }

            $description = trim(($r['type'] ?? 'Amazon').' · '.($r['description'] ?? ''), ' ·');
            $expenses[] = [
                'ref' => md5(implode('|', [$r['settlement id'] ?? '', $r['date/time'] ?? '', $type, $orderId, $r['sku'] ?? '', $r['description'] ?? '', $total])),
                'date' => $date,
                'category' => $this->category($type, strtolower($r['description'] ?? '')),
                'description' => mb_substr($description, 0, 255),
                'amount' => -$total,
            ];
        }

        foreach ($orders as &$order) {
            $order['subtotal'] = round($order['subtotal'], 2);
            $order['fees'] = round($order['fees'], 2);
            $order['refunds'] = round(max(0, $order['refunds']), 2);
        }
        unset($order);

        return $this->result($orders, $expenses, null, $warnings);
    }

    private function category(string $type, string $description): string
    {
        return match (true) {
            str_contains($description, 'advertis') || str_contains($type, 'advertis') => 'advertising',
            str_contains($type, 'fba') || str_contains($type, 'shipping') || str_contains($description, 'storage')
                || str_contains($description, 'inbound') || str_contains($description, 'removal') => 'shipping',
            default => 'fees',
        };
    }
}
