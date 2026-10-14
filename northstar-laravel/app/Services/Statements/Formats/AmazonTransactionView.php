<?php

namespace App\Services\Statements\Formats;

/**
 * Amazon Seller Central → Payments → Transaction View → Download (e.g. "Transactions for time period ….csv").
 *
 * One row per transaction, with totals only: no SKU, no quantity and a shortened product name. It gives each
 * order's actual sales, fees and refunds but can't move stock; upload the All Orders report for line items.
 * Service fees and reimbursements become expenses; repayments charged to the seller's card are transfers.
 */
class AmazonTransactionView extends StatementFormat
{
    private const DATES = ['n/j/Y', 'm/d/Y', 'Y-m-d'];

    public function key(): string
    {
        return 'amazon_transaction_view';
    }

    public function label(): string
    {
        return 'Amazon · Payments transaction view';
    }

    public function platform(): ?string
    {
        return 'Amazon';
    }

    public function hint(): string
    {
        return 'Payments → Transaction View → choose dates → Download. Gives actual fees and charges; upload the All Orders report too for SKUs and stock.';
    }

    public function requiredHeaders(): array
    {
        return ['date', 'transaction status', 'transaction type', 'order id', 'product details', 'total product charges', 'amazon fees', 'other'];
    }

    public function parse(array $records): array
    {
        $orders = [];
        $expenses = [];
        $seen = [];
        $deferred = ['count' => 0, 'amount' => 0.0];
        $transfers = 0.0;
        $currency = null;

        foreach ($records as $r) {
            $date = $this->date($r['date'] ?? '', self::DATES);
            if (! $date) {
                continue;
            }

            // The total column is named after the currency, e.g. "Total (USD)".
            $totalKey = collect(array_keys($r))->first(fn ($k) => str_starts_with($k, 'total (')) ?? 'total';
            $currency ??= preg_match('/^total \((\w{3})\)$/', $totalKey, $m) ? $m[1] : null;

            $type = strtolower(trim($r['transaction type'] ?? ''));
            $orderId = trim($r['order id'] ?? '');
            $orderId = $orderId === '---' ? '' : $orderId;
            $details = trim($r['product details'] ?? '');
            $charges = $this->money($r['total product charges'] ?? '');
            $rebates = $this->money($r['total promotional rebates'] ?? '');
            $other = $this->money($r['other'] ?? '');
            $fees = -$this->money($r['amazon fees'] ?? '');
            $total = $this->money($r[$totalKey] ?? '');

            if (strtolower($r['transaction status'] ?? '') === 'deferred') {
                $deferred['count']++;
                $deferred['amount'] += $total;
            }

            // Multi-Channel Fulfillment orders (S01-…): Amazon shipping another channel's order. A fulfilment cost.
            $isMcf = str_starts_with($orderId, 'S01-');

            if (! $isMcf && $orderId !== '' && in_array($type, ['order payment', 'refund', 'liquidations'], true)) {
                $orders[$orderId] ??= $this->order($orderId, [
                    'placed_at' => $date, 'subtotal' => 0.0, 'fees' => 0.0, 'partial' => true, 'money_only' => true,
                    'customer_name' => $type === 'liquidations' ? 'Amazon liquidation' : null,
                ]);
                $order = &$orders[$orderId];
                $order['fees'] += $fees;
                $order['details'] ??= $details;

                if ($type === 'refund') {
                    $order['refunds'] += -($charges + $rebates + $other);
                } else {
                    // What the buyer paid for the products, after promotions, plus other credits (e.g. shipping).
                    $order['partial'] = false;
                    $order['placed_at'] = min($order['placed_at'], $date);
                    $order['subtotal'] += $charges + $rebates + $other;
                }
                unset($order);

                continue;
            }

            if ($type === 'paid to amazon | seller repayment') {
                $transfers += $total;

                continue;
            }
            if ($total == 0.0) {
                continue; // e.g. inbound placement fees waived to zero
            }

            // Identical rows on one day (two reimbursements of 15.14) are separate charges: number them.
            $base = md5(implode('|', [$date->toDateString(), $type, $orderId, $details, $total]));
            $seen[$base] = ($seen[$base] ?? 0) + 1;

            $expenses[] = [
                'ref' => "atv:{$base}:{$seen[$base]}",
                'date' => $date,
                'category' => $this->category($type, strtolower($details), $isMcf),
                'description' => mb_substr(trim(($isMcf ? 'Multi-Channel Fulfillment' : ucfirst($type)).' · '.($isMcf ? $orderId : rtrim($details, ':')), ' ·'), 0, 255),
                'amount' => -$total,
            ];
        }

        foreach ($orders as &$order) {
            $order['subtotal'] = round(max(0, $order['subtotal']), 2);
            $order['fees'] = round($order['fees'], 2);
            $order['refunds'] = round(max(0, $order['refunds']), 2);
            unset($order['details']);
        }
        unset($order);

        $warnings = [];
        if ($deferred['count']) {
            $warnings[] = sprintf('%d transaction(s) worth %s are Deferred: Amazon holds these funds until the delivery date passes.', $deferred['count'], number_format($deferred['amount'], 2));
        }
        if ($transfers) {
            $warnings[] = sprintf('%s charged to your card by Amazon (seller repayments) was skipped: it is a transfer to cover a negative balance, not income.', number_format($transfers, 2));
        }
        if ($orders) {
            $warnings[] = 'This report has no SKUs or quantities, so stock was not changed. Upload the All Orders report for line items and stock.';
        }

        return $this->result($orders, $expenses, $currency, $warnings);
    }

    private function category(string $type, string $details, bool $mcf): string
    {
        return match (true) {
            $mcf, str_contains($details, 'storage'), str_contains($details, 'inbound'), str_contains($details, 'placement'),
            str_contains($details, 'capacity'), str_contains($details, 'removal'), str_contains($details, 'disposal') => 'shipping',
            str_contains($details, 'coupon'), str_contains($details, 'advertis'), str_contains($details, 'deal'), str_contains($details, 'vine') => 'advertising',
            // Reimbursements are credits: they reduce marketplace fees.
            default => 'fees',
        };
    }
}
