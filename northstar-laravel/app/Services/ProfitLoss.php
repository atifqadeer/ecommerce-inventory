<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Builds a profit & loss statement from orders and expenses, grouped by channel, month or SKU.
 */
class ProfitLoss
{
    public const PERIODS = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_3_months' => 'Last 3 months',
        'last_6_months' => 'Last 6 months',
        'last_12_months' => 'Last 12 months',
        'year_to_date' => 'Year to date',
    ];

    /** Besides the presets: a calendar year ("year:2025") or a range of months ("custom:2025-01:2026-12"). */
    private const YEAR = '/^year:(\d{4})$/';

    private const CUSTOM = '/^custom:(\d{4}-\d{2}):(\d{4}-\d{2})$/';

    public static function isValidPeriod(?string $period): bool
    {
        if ($period === null || isset(self::PERIODS[$period])) {
            return true;
        }
        if (preg_match(self::YEAR, $period, $m)) {
            return $m[1] >= 2000 && $m[1] <= 2100;
        }
        if (preg_match(self::CUSTOM, $period, $m)) {
            $from = CarbonImmutable::createFromFormat('!Y-m', $m[1]);
            $to = CarbonImmutable::createFromFormat('!Y-m', $m[2]);

            // Up to ten years, so a month-by-month statement stays readable.
            return $from && $to && $from->format('Y-m') === $m[1] && $to->format('Y-m') === $m[2]
                && abs($from->diffInMonths($to)) < 120;
        }

        return false;
    }

    /** "Last 6 months", "2025" or "Jan 2025 – Dec 2026". */
    public static function label(string $period): string
    {
        if (preg_match(self::YEAR, $period, $m)) {
            return $m[1];
        }
        if (preg_match(self::CUSTOM, $period)) {
            [$from, $to] = self::ranges($period);

            return $from->format('M Y').' – '.$to->format('M Y');
        }

        return self::PERIODS[$period] ?? self::PERIODS['this_month'];
    }

    public const GROUPS = ['channel', 'month', 'sku'];

    private const METRICS = ['revenue', 'cogs', 'fees', 'payment_processing', 'shipping', 'advertising', 'refunds', 'software'];

    private const OPEX = [
        'fees' => 'Marketplace fees',
        'payment_processing' => 'Payment processing',
        'shipping' => 'Shipping',
        'advertising' => 'Advertising',
        'refunds' => 'Refunds',
        'software' => 'Software & admin',
    ];

    /** Max SKU columns before the rest are merged into "Other". */
    private const SKU_COLUMNS = 6;

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable} current from/to, previous from/to */
    public static function ranges(string $period): array
    {
        $now = CarbonImmutable::now();

        if (preg_match(self::YEAR, $period, $m)) {
            // A calendar year, compared with the year before.
            $year = CarbonImmutable::create((int) $m[1])->startOfYear();

            return [$year, $year->endOfYear(), $year->subYear(), $year->subYear()->endOfYear()];
        }

        if (preg_match(self::CUSTOM, $period, $m)) {
            // Whole months from one to the other (in either order), compared with the same number of months before.
            $a = CarbonImmutable::createFromFormat('!Y-m', $m[1]);
            $b = CarbonImmutable::createFromFormat('!Y-m', $m[2]);
            [$from, $to] = $a <= $b ? [$a, $b] : [$b, $a];
            $months = (int) $from->diffInMonths($to) + 1;

            return [$from->startOfMonth(), $to->endOfMonth(), $from->subMonthsNoOverflow($months)->startOfMonth(), $from->subSecond()];
        }

        if ($period === 'year_to_date') {
            // Compare against the same stretch of last year.
            return [$now->startOfYear(), $now->endOfDay(), $now->subYear()->startOfYear(), $now->subYear()->endOfDay()];
        }

        if ($period === 'this_month') {
            // Month to date vs the same days of last month.
            $lastMonth = $now->subMonthNoOverflow();

            return [$now->startOfMonth(), $now->endOfDay(), $lastMonth->startOfMonth(), $lastMonth->endOfDay()];
        }

        [$from, $to, $months] = match ($period) {
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth(), 1],
            'last_3_months' => [$now->subMonthsNoOverflow(2)->startOfMonth(), $now->endOfMonth(), 3],
            'last_6_months' => [$now->subMonthsNoOverflow(5)->startOfMonth(), $now->endOfMonth(), 6],
            'last_12_months' => [$now->subMonthsNoOverflow(11)->startOfMonth(), $now->endOfMonth(), 12],
            default => [$now->startOfMonth(), $now->endOfMonth(), 1],
        };

        return [$from, $to, $from->subMonthsNoOverflow($months), $from->subSecond()];
    }

    /**
     * @param  ?array<int, int>  $channelIds  limit to these stores; shared expenses (no store) are included in
     *                                        proportion to the stores' share of sales, as in the "by channel" view
     */
    public static function build(User $user, string $period = 'this_month', string $group = 'channel', ?array $channelIds = null): array
    {
        [$from, $to, $prevFrom, $prevTo] = self::ranges($period);

        $orders = $user->orders()->with('items')
            ->whereBetween('placed_at', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->when($channelIds !== null, fn ($q) => $q->whereIn('channel_id', $channelIds))
            ->get();
        $expenses = self::expenses($user, $from, $to, $channelIds, $orders->sum('subtotal'));

        $columns = self::columns($user, $group, $from, $to, $orders, $channelIds);
        $buckets = self::aggregate($group, $columns, $orders, $expenses);
        $current = self::sumBuckets($buckets);
        $previous = self::totals($user, $prevFrom, $prevTo, $channelIds);

        $line = function (string $key, string $label, callable $value, bool $cost = false) use ($columns, $buckets, $current, $previous) {
            $values = array_map(fn ($c) => round($value($buckets[$c['key']]), 2), $columns);
            $total = round($value($current), 2);
            $prev = round($value($previous), 2);
            // Costs are stored as negatives, so compare magnitudes: "+10%" means the cost grew by 10%.
            $variance = $prev == 0.0 ? null : round(($cost ? abs($total) - abs($prev) : $total - $prev) / abs($prev) * 100, 1);

            return [
                'key' => $key,
                'label' => $label,
                'values' => $values,
                'total' => $total,
                'previous' => $prev,
                'variance' => $variance,
                'favourable' => $variance === null ? null : ($cost ? $variance <= 0 : $variance >= 0),
            ];
        };

        $gross = fn ($m) => $m['revenue'] - $m['cogs'];
        $opex = fn ($m) => array_sum(array_map(fn ($k) => $m[$k], array_keys(self::OPEX)));
        $net = fn ($m) => $gross($m) - $opex($m);

        $lines = [
            $line('revenue', 'Revenue', fn ($m) => $m['revenue']) + ['type' => 'group', 'children' => [
                $line('gross_sales', 'Gross sales', fn ($m) => $m['revenue']),
            ]],
            $line('cogs', 'Cost of Goods Sold', fn ($m) => -$m['cogs'], true) + ['type' => 'group', 'children' => [
                $line('product_costs', 'Product costs', fn ($m) => -$m['cogs'], true),
            ]],
            $line('gross_profit', 'Gross Profit', $gross) + ['type' => 'subtotal'],
            $line('opex', 'Operating Expenses', fn ($m) => -$opex($m), true) + ['type' => 'group', 'children' => array_map(
                fn ($k) => $line($k, self::OPEX[$k], fn ($m) => -$m[$k], true),
                array_keys(self::OPEX),
            )],
            $line('net_profit', 'Net Profit', $net) + ['type' => 'subtotal'],
        ];

        $margin = fn ($m) => $m['revenue'] > 0 ? round($net($m) / $m['revenue'] * 100, 1) : 0.0;

        return [
            'period' => [
                'key' => $period,
                'label' => self::label($period),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'previous_from' => $prevFrom->toDateString(),
                'previous_to' => $prevTo->toDateString(),
            ],
            'group' => $group,
            'columns' => $columns,
            'lines' => $lines,
            'summary' => [
                'revenue' => $lines[0],
                'gross_profit' => $lines[2],
                'opex' => $lines[3],
                'net_profit' => $lines[4],
                'net_margin' => $margin($current),
                'previous_net_margin' => $margin($previous),
            ],
            'waterfall' => [
                ['label' => 'Revenue', 'value' => round($current['revenue'], 2), 'kind' => 'total'],
                ['label' => 'COGS', 'value' => round($current['cogs'], 2), 'kind' => 'cost'],
                ['label' => 'Fees', 'value' => round($current['fees'] + $current['payment_processing'], 2), 'kind' => 'cost'],
                ['label' => 'Shipping', 'value' => round($current['shipping'], 2), 'kind' => 'cost'],
                ['label' => 'Ads', 'value' => round($current['advertising'], 2), 'kind' => 'cost'],
                ['label' => 'Other', 'value' => round($current['refunds'] + $current['software'], 2), 'kind' => 'cost'],
                ['label' => 'Net profit', 'value' => round($net($current), 2), 'kind' => 'result'],
            ],
        ];
    }

    /** Flatten the statement into CSV rows. */
    public static function toCsv(array $statement): string
    {
        $rows = [array_merge(['Line item'], array_column($statement['columns'], 'label'), ['Total', 'Previous period'])];
        foreach ($statement['lines'] as $line) {
            $rows[] = array_merge([$line['label']], $line['values'], [$line['total'], $line['previous']]);
            foreach ($line['children'] ?? [] as $child) {
                $rows[] = array_merge(['  '.$child['label']], $child['values'], [$child['total'], $child['previous']]);
            }
        }

        return Csv::fromRows($rows);
    }

    /** Headline totals for a range, ignoring grouping (used for the comparison period). */
    public static function totals(User $user, CarbonImmutable $from, CarbonImmutable $to, ?array $channelIds = null): array
    {
        $orders = $user->orders()->whereBetween('placed_at', [$from, $to])->where('status', '!=', 'cancelled')
            ->when($channelIds !== null, fn ($q) => $q->whereIn('channel_id', $channelIds))->get();
        $expenses = self::expenses($user, $from, $to, $channelIds, $orders->sum('subtotal'));

        $totals = self::emptyMetrics();
        foreach ($orders as $order) {
            self::addOrder($totals, $order, 1.0);
        }
        foreach ($expenses as $expense) {
            $totals[$expense->category] += $expense->amount;
        }

        return $totals;
    }

    /**
     * Expenses in a range. For a subset of stores: their own expenses, plus shared expenses scaled by the
     * subset's share of all sales in the range (so the stores' P&Ls add up to the whole business).
     */
    private static function expenses(User $user, CarbonImmutable $from, CarbonImmutable $to, ?array $channelIds, float $revenue)
    {
        $expenses = $user->expenses()
            ->whereDate('incurred_on', '>=', $from->toDateString())
            ->whereDate('incurred_on', '<=', $to->toDateString())
            ->when($channelIds !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('channel_id', $channelIds)->orWhereNull('channel_id')))
            ->get();
        if ($channelIds === null) {
            return $expenses;
        }

        $allRevenue = (float) $user->orders()->whereBetween('placed_at', [$from, $to])->where('status', '!=', 'cancelled')->sum('subtotal');
        $share = $allRevenue > 0 ? $revenue / $allRevenue : 0.0;

        return $expenses->map(function ($expense) use ($share) {
            if ($expense->channel_id !== null) {
                return $expense;
            }
            $portion = clone $expense;
            $portion->amount = round($expense->amount * $share, 2);

            return $portion;
        })->filter(fn ($e) => $e->amount != 0.0)->values();
    }

    private static function columns(User $user, string $group, CarbonImmutable $from, CarbonImmutable $to, $orders, ?array $channelIds = null): array
    {
        if ($group === 'month') {
            return collect(CarbonPeriod::create($from->startOfMonth(), '1 month', $to))
                ->map(fn ($month) => ['key' => $month->format('Y-m'), 'label' => $month->format('M Y')])
                ->values()->all();
        }

        if ($group === 'sku') {
            $revenueBySku = [];
            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    $revenueBySku[$item->sku] = ($revenueBySku[$item->sku] ?? 0) + $item->quantity * $item->unit_price;
                }
            }
            arsort($revenueBySku);
            $columns = array_map(fn ($sku) => ['key' => $sku, 'label' => $sku], array_slice(array_keys($revenueBySku), 0, self::SKU_COLUMNS));
            // Orders known only from payments have no line items: they go in "Other" rather than disappearing.
            $unitemised = $orders->contains(fn ($o) => $o->items->sum(fn ($i) => $i->quantity * $i->unit_price) <= 0);
            if (count($revenueBySku) > self::SKU_COLUMNS || ($unitemised && $columns)) {
                $columns[] = ['key' => '__other', 'label' => $unitemised ? 'Other / no SKU' : 'Other'];
            }

            return $columns ?: [['key' => '__other', 'label' => 'All SKUs']];
        }

        return $user->channels()->when($channelIds !== null, fn ($q) => $q->whereIn('id', $channelIds))->orderBy('id')->get()
            ->map(fn ($c) => ['key' => (string) $c->id, 'label' => $c->name, 'color' => $c->color])
            ->all();
    }

    private static function aggregate(string $group, array $columns, $orders, $expenses): array
    {
        $buckets = [];
        foreach ($columns as $column) {
            $buckets[$column['key']] = self::emptyMetrics();
        }
        if (! $buckets) {
            return $buckets;
        }
        $keys = array_keys($buckets);
        $bucketFor = fn (string $key) => isset($buckets[$key]) ? $key : (isset($buckets['__other']) ? '__other' : null);

        foreach ($orders as $order) {
            if ($group === 'sku') {
                // Split the order across its lines by their share of the items' value, so the parts add up to
                // the whole order even when its recorded sales differ from the item prices.
                $itemsValue = $order->items->sum(fn ($i) => $i->quantity * $i->unit_price);
                if ($itemsValue <= 0) {
                    if ($key = $bucketFor('__other')) {
                        self::addOrder($buckets[$key], $order, 1.0);
                    }

                    continue;
                }
                foreach ($order->items as $item) {
                    if ($key = $bucketFor($item->sku)) {
                        self::addOrder($buckets[$key], $order, $item->quantity * $item->unit_price / $itemsValue, $item->quantity * $item->unit_cost);
                    }
                }
            } elseif ($key = $bucketFor($group === 'month' ? $order->placed_at->format('Y-m') : (string) $order->channel_id)) {
                self::addOrder($buckets[$key], $order, 1.0);
            }
        }

        // Expenses without a natural column (shared costs, or SKU view) are spread by revenue share.
        $totalRevenue = array_sum(array_column($buckets, 'revenue'));
        $spread = function (array &$buckets, string $category, float $amount) use ($keys, $totalRevenue) {
            foreach ($keys as $key) {
                $weight = $totalRevenue > 0 ? $buckets[$key]['revenue'] / $totalRevenue : 1 / count($keys);
                $buckets[$key][$category] += $amount * $weight;
            }
        };

        foreach ($expenses as $expense) {
            $key = match ($group) {
                'month' => $expense->incurred_on->format('Y-m'),
                'channel' => $expense->channel_id ? (string) $expense->channel_id : null,
                default => null,
            };
            if ($key !== null && isset($buckets[$key])) {
                $buckets[$key][$expense->category] += $expense->amount;
            } else {
                $spread($buckets, $expense->category, $expense->amount);
            }
        }

        return $buckets;
    }

    private static function addOrder(array &$metrics, Order $order, float $share, ?float $cogs = null): void
    {
        $metrics['revenue'] += $order->subtotal * $share;
        $metrics['cogs'] += $cogs ?? $order->cogs * $share;
        $metrics['fees'] += $order->channel_fees * $share;
        $metrics['shipping'] += $order->shipping_cost * $share;
        $metrics['refunds'] += $order->refunds * $share;
    }

    private static function sumBuckets(array $buckets): array
    {
        $totals = self::emptyMetrics();
        foreach ($buckets as $bucket) {
            foreach (self::METRICS as $metric) {
                $totals[$metric] += $bucket[$metric];
            }
        }

        return $totals;
    }

    private static function emptyMetrics(): array
    {
        return array_fill_keys(self::METRICS, 0.0);
    }
}
