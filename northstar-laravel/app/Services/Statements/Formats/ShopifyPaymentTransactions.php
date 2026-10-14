<?php

namespace App\Services\Statements\Formats;

use App\Models\PaymentTransaction;

/** Shopify admin → Finance → Payouts → Export transactions (payment_transactions.csv). One row per money movement. */
class ShopifyPaymentTransactions extends StatementFormat
{
    public function key(): string
    {
        return 'shopify_payment_transactions';
    }

    public function label(): string
    {
        return 'Shopify · Payment transactions';
    }

    public function platform(): ?string
    {
        return 'Shopify';
    }

    public function hint(): string
    {
        return 'Finance → Payouts → Export → "Transactions". Gives actual fees, refunds, chargebacks and Shop campaign costs.';
    }

    public function requiredHeaders(): array
    {
        return ['transaction date', 'type', 'order', 'amount', 'fee', 'net'];
    }

    public function parse(array $records): array
    {
        $transactions = [];
        $currency = null;
        $unknown = [];

        foreach ($records as $r) {
            $occurredAt = $this->date($r['transaction date'] ?? '');
            if (! $occurredAt) {
                continue;
            }
            $currency ??= ($r['currency'] ?? '') ?: null;

            $type = str_replace([' ', '-'], '_', strtolower(trim($r['type'] ?? '')));
            if (! isset(PaymentTransaction::TYPES[$type])) {
                $unknown[$type] = ($unknown[$type] ?? 0) + 1;
            }

            $transactions[] = [
                'type' => isset(PaymentTransaction::TYPES[$type]) ? $type : 'other',
                'order_ref' => $r['order'] ?? null,
                'occurred_at' => $occurredAt,
                'amount' => $this->money($r['amount'] ?? ''),
                'fee' => $this->money($r['fee'] ?? ''),
                'net' => $this->money($r['net'] ?? ''),
                'currency' => ($r['currency'] ?? '') ?: null,
                'payout_date' => ($r['payout date'] ?? '') ?: null,
                'payout_ref' => ($r['payout id'] ?? '') ?: null,
                'payout_status' => ($r['payout status'] ?? '') ?: null,
                'note' => isset(PaymentTransaction::TYPES[$type]) ? null : ($r['type'] ?? null),
            ];
        }

        $warnings = array_map(
            fn ($type, $count) => "{$count} row(s) of type \"{$type}\" were saved but don't affect profit.",
            array_keys($unknown), $unknown,
        );

        return $this->result([], [], $currency, $warnings, $transactions);
    }
}
