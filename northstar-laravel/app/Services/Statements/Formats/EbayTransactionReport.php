<?php

namespace App\Services\Statements\Formats;

/**
 * eBay Seller Hub → Payments → Reports → "Transaction report". Orders and refunds carry sales and
 * every fee column; shipping labels and other fees become expenses. Payouts are ignored.
 */
class EbayTransactionReport extends StatementFormat
{
    public function key(): string
    {
        return 'ebay_transactions';
    }

    public function label(): string
    {
        return 'eBay · Transaction report';
    }

    public function platform(): ?string
    {
        return 'eBay';
    }

    public function hint(): string
    {
        return 'Seller Hub → Payments → Reports → Create report → "Transaction report" (CSV).';
    }

    public function requiredHeaders(): array
    {
        return ['transaction creation date', 'type', 'order number', 'item subtotal', 'net amount'];
    }

    public function parse(array $records): array
    {
        $orders = [];
        $expenses = [];
        $currency = null;
        $dates = ['M d, Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'];

        foreach ($records as $r) {
            $type = strtolower($r['type'] ?? '');
            $date = $this->date($r['transaction creation date'] ?? '', $dates);
            if (! $date) {
                continue;
            }
            $currency ??= $this->value($r, 'transaction currency', 'payout currency') ?: null;
            $orderId = $r['order number'] ?? '';
            // Every column with "fee" in its name holds a (negative) fee amount.
            $fees = -array_sum(array_map(fn ($key) => str_contains($key, 'fee') && ! str_contains($key, 'type') ? $this->money($r[$key]) : 0, array_keys($r)));

            if (in_array($type, ['order', 'refund'], true) && $orderId !== '') {
                $orders[$orderId] ??= $this->order($orderId, ['placed_at' => $date, 'subtotal' => 0.0, 'fees' => 0.0, 'partial' => true]);
                $order = &$orders[$orderId];
                $order['fees'] += $fees;

                if ($type === 'order') {
                    $order['partial'] = false;
                    $order['placed_at'] = min($order['placed_at'], $date);
                    $order['customer_name'] ??= $this->value($r, 'buyer name', 'buyer username') ?: null;
                    $order['shipping_address'] ??= $this->address('', '', $r['ship to city'] ?? '', $r['ship to province/region/state'] ?? '', $r['ship to zip'] ?? '', $r['ship to country'] ?? '');
                    $subtotal = $this->money($r['item subtotal'] ?? '');
                    $order['subtotal'] += $subtotal;
                    $quantity = $this->quantity($r['quantity'] ?? '');
                    if ($quantity > 0) {
                        $order['lines'][] = [
                            'sku' => $r['custom label'] ?? '',
                            'name' => $r['item title'] ?? '',
                            'quantity' => $quantity,
                            'unit_price' => round($subtotal / $quantity, 2),
                        ];
                    }
                } else {
                    $order['refunds'] += abs($this->money($this->value($r, 'gross transaction amount', 'item subtotal', 'net amount')));
                }
                unset($order);

                continue;
            }

            $net = $this->money($r['net amount'] ?? '');
            if (in_array($type, ['payout', 'transfer', 'hold'], true) || $net == 0.0) {
                continue;
            }

            $expenses[] = [
                'ref' => md5(implode('|', [$r['transaction creation date'] ?? '', $type, $orderId, $r['transaction id'] ?? '', $r['description'] ?? '', $net])),
                'date' => $date,
                'category' => str_contains($type, 'shipping') ? 'shipping' : (str_contains(strtolower($r['description'] ?? ''), 'promot') ? 'advertising' : 'fees'),
                'description' => mb_substr(trim(($r['type'] ?? 'eBay').' · '.($r['description'] ?? ''), ' ·'), 0, 255),
                'amount' => -$net,
            ];
        }

        return $this->result($orders, $expenses, $currency);
    }
}
