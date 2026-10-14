<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Marketplace receivables: money a store has earned (sales less fees, refunds, tax and charges) that the
 * marketplace hasn't paid to the bank yet.
 *
 *  - stores with payment transactions and payouts: earned = each transaction's net when it happens,
 *    collected = each paid payout. The balance is exactly what the payment account still holds;
 *  - stores with transactions only: collected when a transaction's payout is marked paid;
 *  - stores with payouts only: the scheduled and in-transit payouts are what's owed;
 *  - stores with orders only: cash is estimated on the order date, so nothing is outstanding.
 */
class Receivables
{
    /**
     * @param  callable(array): string  $columnOf  maps an event to a statement column key
     */
    public static function build(User $user, CarbonImmutable $from, CarbonImmutable $to, array $columns, callable $columnOf, ?Channel $store = null): array
    {
        $today = CarbonImmutable::today()->toDateString();
        [$events, $tracked] = self::events($user, $store, $today);

        $before = $events->filter(fn ($e) => $e['date'] < $from->toDateString());
        $during = $events->filter(fn ($e) => $e['date'] >= $from->toDateString() && $e['date'] <= $to->toDateString());
        $sum = fn (Collection $items, string $kind) => round($items->where('kind', $kind)->sum('amount'), 2);
        $values = fn (Collection $items, string $kind) => array_map(
            fn ($c) => round($items->where('kind', $kind)->filter(fn ($e) => $columnOf($e) === $c['key'])->sum('amount'), 2),
            $columns,
        );

        $opening = round($sum($before, 'earned') - $sum($before, 'collected'), 2);
        $earned = $sum($during, 'earned');
        $collected = $sum($during, 'collected');

        // Balance at the end of each month in the period.
        $monthEnds = [];
        $balance = $opening;
        foreach ($during->groupBy(fn ($e) => substr($e['date'], 0, 7))->sortKeys() as $month => $items) {
            $balance += $sum($items, 'earned') - $sum($items, 'collected');
            $monthEnds[$month] = round($balance, 2);
        }

        $outstanding = self::outstanding($user, $store, $tracked);
        $current = round($events->where('kind', 'earned')->sum('amount') - $events->where('kind', 'collected')->sum('amount'), 2);
        $notes = [];
        if (abs($current - array_sum(array_column($outstanding, 'amount'))) >= 0.01 && $outstanding) {
            $notes[] = sprintf('%s of the receivable balance isn\'t matched to a pending transaction or payout. Check that payouts and payment transactions are imported for the same dates.',
                number_format(abs($current - array_sum(array_column($outstanding, 'amount'))), 2));
        }

        return [
            'tracked' => (bool) $tracked,
            'opening' => $opening,
            'earned' => ['values' => $values($during, 'earned'), 'total' => $earned],
            'collected' => ['values' => array_map(fn ($v) => -$v, $values($during, 'collected')), 'total' => -$collected],
            'closing' => round($opening + $earned - $collected, 2),
            'current' => $current,
            'month_ends' => $monthEnds,
            'outstanding' => $outstanding,
            'next_payout' => collect($outstanding)->first(fn ($o) => $o['date'] >= $today)['date'] ?? null,
            // Pending/scheduled statuses are as of the latest data imported; anything due before today is stale.
            'as_of' => $events->max('date'),
            'overdue' => round(collect($outstanding)->filter(fn ($o) => $o['date'] && $o['date'] < $today)->sum('amount'), 2),
            'notes' => $notes,
        ];
    }

    /**
     * Earned and collected events for every store that has payment data.
     *
     * @return array{0: Collection<int, array{date: string, channel_id: int, kind: string, amount: float}>, 1: array<int, string>} events, and how each store is tracked
     */
    private static function events(User $user, ?Channel $store, string $today): array
    {
        $events = [];
        $tracked = [];
        $add = function (string $date, int $channelId, string $kind, float $amount) use (&$events) {
            if ($amount != 0.0) {
                $events[] = ['date' => $date, 'channel_id' => $channelId, 'kind' => $kind, 'amount' => round($amount, 2)];
            }
        };

        $channels = $user->channels()->when($store, fn ($q) => $q->whereKey($store->id))->withCount(['payouts', 'paymentTransactions'])->get();
        foreach ($channels as $channel) {
            if ($channel->payment_transactions_count) {
                foreach ($channel->paymentTransactions()->get(['occurred_at', 'net', 'payout_date', 'payout_status']) as $t) {
                    $add($t->occurred_at->toDateString(), $channel->id, 'earned', $t->net);
                    // Without payouts on file, a transaction counts as collected once its payout is paid. One typed in
                    // with no payout details counts as paid on its own date. (Same rule as the cash flow.)
                    $paid = $t->payout_status === 'paid' || (! $t->payout_status && (! $t->payout_date || $t->payout_date->toDateString() <= $today));
                    if (! $channel->payouts_count && $paid) {
                        $add(($t->payout_date ?? $t->occurred_at)->toDateString(), $channel->id, 'collected', $t->net);
                    }
                }
                if ($channel->payouts_count) {
                    foreach ($channel->payouts()->where('status', 'paid')->get(['paid_on', 'total']) as $p) {
                        $add($p->paid_on->toDateString(), $channel->id, 'collected', $p->total);
                    }
                }
                $tracked[$channel->id] = 'transactions';
            } elseif ($channel->payouts_count) {
                // Only payouts on file: what's scheduled or on its way is owed as of today.
                $pending = $channel->payouts()->whereIn('status', ['scheduled', 'in_transit'])->sum('total');
                $add($today, $channel->id, 'earned', (float) $pending);
                $tracked[$channel->id] = 'payouts';
            }
        }

        return [collect($events), $tracked];
    }

    /**
     * What's owed right now, by the date it's expected to be paid out.
     *
     * @return array<int, array{date: ?string, status: string, amount: float, count: int, store: string}>
     */
    private static function outstanding(User $user, ?Channel $store, array $tracked): array
    {
        $rows = collect();
        $names = $user->channels()->pluck('name', 'id');

        foreach ($tracked as $channelId => $method) {
            if ($method === 'transactions') {
                $user->paymentTransactions()->where('channel_id', $channelId)
                    ->where(fn ($q) => $q->whereNull('payout_status')->orWhere('payout_status', '!=', 'paid'))
                    ->where(fn ($q) => $q->whereNotNull('payout_status')->orWhereNull('payout_date')->orWhere('payout_date', '>', CarbonImmutable::today()->toDateString()))
                    ->selectRaw('payout_date, payout_status, COUNT(*) as count, SUM(net) as amount')
                    ->groupBy('payout_date', 'payout_status')->toBase()->get()
                    ->each(fn ($r) => $rows->push(['date' => $r->payout_date, 'status' => $r->payout_status ?: 'pending', 'amount' => round((float) $r->amount, 2), 'count' => (int) $r->count, 'store' => $names[$channelId]]));
            } else {
                $user->payouts()->where('channel_id', $channelId)->whereIn('status', ['scheduled', 'in_transit'])->get()
                    ->each(fn ($p) => $rows->push(['date' => $p->paid_on->toDateString(), 'status' => $p->status, 'amount' => $p->total, 'count' => 1, 'store' => $names[$channelId]]));
            }
        }

        return $rows->sortBy(fn ($r) => $r['date'] ?? '9999')->values()->all();
    }
}
