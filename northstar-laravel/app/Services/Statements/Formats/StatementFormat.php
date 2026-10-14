<?php

namespace App\Services\Statements\Formats;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * A marketplace report layout. Each format turns the report's records into normalised orders and expenses:
 *
 *   order:   ref, number, placed_at, status, customer_name, customer_email, shipping_address, fulfillment,
 *            lines [sku, name, quantity, unit_price], subtotal (null = sum of lines), fees (null = estimate),
 *            shipping_cost, refunds, partial (true when the report only has a refund/adjustment for the order)
 *   expense: ref, date, category, description, amount (positive = cost)
 *   transaction: type, order_ref, occurred_at, amount, fee, net, currency, payout_date, payout_ref, payout_status, note
 *   payout:  paid_on, status, charges, refunds, adjustments, tax, fees, total, currency, bank_reference
 */
abstract class StatementFormat
{
    /** Stable identifier stored on each import. */
    abstract public function key(): string;

    /** Name shown in the upload form, e.g. "Amazon · Date range transaction report". */
    abstract public function label(): string;

    /** Platform the report comes from, or null for the generic template. */
    abstract public function platform(): ?string;

    /** Where the seller downloads this report. */
    abstract public function hint(): string;

    /** Header names that identify this report; an entry may be a list of alternatives. */
    abstract public function requiredHeaders(): array;

    /**
     * @param  array<int, array<string, string>>  $records
     * @return array{orders: array<string, array>, expenses: array<int, array>, transactions: array<int, array>, payouts: array<int, array>, currency: ?string, warnings: array<int, string>}
     */
    abstract public function parse(array $records): array;

    protected function order(string $ref, array $attributes = []): array
    {
        return $attributes + [
            'ref' => $ref,
            'number' => $ref,
            'placed_at' => null,
            'status' => 'shipped',
            'customer_name' => null,
            'customer_email' => null,
            'shipping_address' => null,
            'fulfillment' => 'FBM',
            'lines' => [],
            'subtotal' => null,
            'fees' => null,
            'shipping_cost' => 0.0,
            'refunds' => 0.0,
            'partial' => false,
        ];
    }

    /** First non-empty value among the given columns. */
    protected function value(array $record, string ...$columns): string
    {
        foreach ($columns as $column) {
            if (($record[$column] ?? '') !== '') {
                return $record[$column];
            }
        }

        return '';
    }

    /** "$1,234.50", "(12.00)", "-£3.40", "1.234,56" → float. */
    protected function money(?string $value): float
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '--') {
            return 0.0;
        }

        $negative = str_starts_with($value, '(') && str_ends_with($value, ')') || str_contains($value, '-');
        $number = preg_replace('/[^0-9.,]/', '', $value);

        if (str_contains($number, ',') && str_contains($number, '.')) {
            // Whichever separator comes last is the decimal point.
            $number = strrpos($number, ',') > strrpos($number, '.')
                ? str_replace(',', '.', str_replace('.', '', $number))
                : str_replace(',', '', $number);
        } elseif (preg_match('/,\d{1,2}$/', $number)) {
            $number = str_replace(',', '.', $number);
        } else {
            $number = str_replace(',', '', $number);
        }

        return round(($negative ? -1 : 1) * (float) $number, 2);
    }

    protected function quantity(?string $value): int
    {
        return (int) round($this->money($value));
    }

    /** Parse a report date, trying explicit formats before PHP's own parser. */
    protected function date(?string $value, array $formats = []): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach ($formats as $format) {
            try {
                // "!" resets fields the format doesn't mention, so date-only formats give midnight.
                if ($date = Carbon::createFromFormat('!'.$format, $value)) {
                    return $date;
                }
            } catch (Throwable) {
                // try the next format
            }
        }

        try {
            // "GMT+1"/"UTC" style suffixes from Amazon reports are understood by Carbon::parse.
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    protected function address(string ...$parts): ?string
    {
        [$street1, $street2, $city, $state, $zip, $country] = array_pad($parts, 6, '');
        $lines = [
            trim("{$street1} {$street2}"),
            trim(trim("{$city}, {$state} {$zip}"), ', '),
            $country,
        ];

        return implode("\n", array_filter($lines)) ?: null;
    }

    protected function result(array $orders, array $expenses = [], ?string $currency = null, array $warnings = [], array $transactions = [], array $payouts = []): array
    {
        return [
            'orders' => $orders,
            'expenses' => $expenses,
            'transactions' => $transactions,
            'payouts' => $payouts,
            'currency' => $currency ? strtoupper($currency) : null,
            'warnings' => $warnings,
        ];
    }
}
