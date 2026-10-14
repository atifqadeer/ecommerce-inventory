<?php

namespace App\Services\Statements\Formats;

use App\Models\Payout;

/** Shopify admin → Finance → Payouts → Export payouts (payout.csv). One row per bank transfer. */
class ShopifyPayouts extends StatementFormat
{
    public function key(): string
    {
        return 'shopify_payouts';
    }

    public function label(): string
    {
        return 'Shopify · Payouts';
    }

    public function platform(): ?string
    {
        return 'Shopify';
    }

    public function hint(): string
    {
        return 'Finance → Payouts → Export → "Payouts". Paid payouts are recorded as cash received.';
    }

    public function requiredHeaders(): array
    {
        return ['payout date', 'status', 'charges', 'fees', 'total'];
    }

    public function parse(array $records): array
    {
        $payouts = [];
        $currency = null;

        foreach ($records as $r) {
            $date = $this->date($r['payout date'] ?? '', ['Y-m-d']);
            if (! $date) {
                continue;
            }
            $currency ??= ($r['currency'] ?? '') ?: null;
            $status = str_replace(' ', '_', strtolower($r['status'] ?? 'paid'));

            $payouts[] = [
                'paid_on' => $date->toDateString(),
                'status' => in_array($status, Payout::STATUSES, true) ? $status : 'paid',
                'charges' => $this->money($r['charges'] ?? ''),
                'refunds' => $this->money($r['refunds'] ?? ''),
                'adjustments' => $this->money($r['adjustments'] ?? ''),
                'tax' => $this->money($r['marketplace sales tax'] ?? ''),
                // Shopify lists fees as a positive amount taken out of the payout.
                'fees' => abs($this->money($r['fees'] ?? '')),
                'total' => $this->money($r['total'] ?? ''),
                'currency' => ($r['currency'] ?? '') ?: null,
                'bank_reference' => ($r['bank reference'] ?? '') ?: null,
            ];
        }

        return $this->result([], [], $currency, [], [], $payouts);
    }
}
