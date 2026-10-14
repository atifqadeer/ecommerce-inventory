<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Expense;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Payout;
use App\Models\StatementImport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single entry point for payment transactions and payouts, whether typed in or imported, so the same
 * record is never stored twice:
 *
 *  - every record has a match key (store + type + order + amount + day for transactions, store + day + amount for payouts);
 *  - an import that finds a matching record updates it (taking over a manual entry) instead of adding a copy;
 *  - a manual entry that matches an existing record is refused unless the user confirms it's a separate one.
 *
 * Order-related transactions set the order's actual fees and refunds; costs such as Shop campaigns become
 * expenses; paid payouts are posted to the ledger as cash received.
 */
class PaymentLedger
{
    /**
     * @param  array{type: string, order_ref?: ?string, occurred_at: Carbon, amount: float, fee?: float, net?: ?float, currency?: ?string,
     *               payout_date?: mixed, payout_ref?: ?string, payout_status?: ?string, note?: ?string}  $data
     * @return array{0: PaymentTransaction, 1: bool} the transaction, and whether it matched an existing one
     */
    public static function saveTransaction(Channel $channel, array $data, ?StatementImport $import = null, int $occurrence = 1, bool $allowDuplicate = false): array
    {
        $orderRef = ($ref = ltrim(trim((string) ($data['order_ref'] ?? '')), '#')) !== '' ? $ref : null;
        $amount = round($data['amount'], 2);
        $fee = round($data['fee'] ?? 0, 2);
        $key = PaymentTransaction::matchKey($data['type'], $orderRef, $amount, $data['occurred_at']->toDateString());

        $attributes = [
            'type' => $data['type'],
            'order_ref' => $orderRef,
            'occurred_at' => $data['occurred_at'],
            'amount' => $amount,
            'fee' => $fee,
            'net' => round($data['net'] ?? $amount - $fee, 2),
            'currency' => $data['currency'] ?? $channel->currency,
            'payout_date' => $data['payout_date'] ?? null,
            'payout_ref' => $data['payout_ref'] ?? null,
            'payout_status' => $data['payout_status'] ?? null,
            'note' => $data['note'] ?? null,
        ];

        [$transaction, $matched, $occurrence] = self::upsert($channel->id, PaymentTransaction::query(), $key, $occurrence, $import, $allowDuplicate,
            fn (PaymentTransaction $existing) => sprintf('%s of %s on %s%s', $existing->type_label, number_format(abs($existing->amount), 2),
                $existing->occurred_at->toFormattedDateString(), $existing->order_ref ? " for order #{$existing->order_ref}" : ''),
        );
        if (! $transaction) {
            $transaction = $channel->user->paymentTransactions()->make(['channel_id' => $channel->id, 'match_key' => $key, 'occurrence' => $occurrence]);
        }
        $transaction->fill($attributes + self::sourceAttributes($transaction, $import))->save();

        self::link($transaction);

        return [$transaction, $matched];
    }

    /**
     * @param  array{paid_on: string, status?: string, total: float, charges?: float, refunds?: float, adjustments?: float, tax?: float,
     *               fees?: float, currency?: ?string, bank_reference?: ?string}  $data
     * @return array{0: Payout, 1: bool}
     */
    public static function savePayout(Channel $channel, array $data, ?StatementImport $import = null, int $occurrence = 1, bool $allowDuplicate = false): array
    {
        $total = round($data['total'], 2);
        $key = Payout::matchKey($data['paid_on'], $total);

        [$payout, $matched, $occurrence] = self::upsert($channel->id, Payout::query(), $key, $occurrence, $import, $allowDuplicate,
            fn (Payout $existing) => sprintf('payout of %s on %s', number_format($existing->total, 2), $existing->paid_on->toFormattedDateString()),
        );
        if (! $payout) {
            $payout = $channel->user->payouts()->make(['channel_id' => $channel->id, 'match_key' => $key, 'occurrence' => $occurrence]);
        }

        $payout->fill([
            'paid_on' => $data['paid_on'],
            'status' => $data['status'] ?? 'paid',
            'total' => $total,
            'charges' => round($data['charges'] ?? 0, 2),
            'refunds' => round($data['refunds'] ?? 0, 2),
            'adjustments' => round($data['adjustments'] ?? 0, 2),
            'tax' => round($data['tax'] ?? 0, 2),
            'fees' => round($data['fees'] ?? 0, 2),
            'currency' => $data['currency'] ?? $channel->currency,
            // A manual entry's bank reference is kept if the import has none.
            'bank_reference' => ($data['bank_reference'] ?? null) ?: $payout->bank_reference,
        ] + self::sourceAttributes($payout, $import))->save();

        self::postPayout($payout);

        return [$payout, $matched];
    }

    public static function deleteTransaction(PaymentTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $order = $transaction->order;
            $transaction->expense?->delete();
            $transaction->delete();
            if ($order) {
                self::touch($order);
            }
        });
    }

    public static function deletePayout(Payout $payout): void
    {
        DB::transaction(function () use ($payout) {
            $payout->journalEntry?->delete();
            $payout->delete();
        });
    }

    /** Undo an import's effect on a record: imported ones are deleted, manual ones it took over go back to manual. */
    public static function detachImport(StatementImport $import): void
    {
        self::batch(fn () => self::detachTransactions($import));
        foreach (Payout::where('statement_import_id', $import->id)->get() as $payout) {
            $payout->entered_manually
                ? $payout->update(['source' => 'manual', 'statement_import_id' => null])
                : self::deletePayout($payout);
        }
    }

    private static function detachTransactions(StatementImport $import): void
    {
        foreach (PaymentTransaction::where('statement_import_id', $import->id)->get() as $transaction) {
            $transaction->entered_manually
                ? $transaction->update(['source' => 'manual', 'statement_import_id' => null])
                : self::deleteTransaction($transaction);
        }
    }

    /** Re-derive an order's actual fees and refunds (and, for orders known only from payments, its sales) from its transactions. */
    public static function refreshOrder(Order $order): void
    {
        $transactions = $order->paymentTransactions()->get();
        $paymentsOnly = ! $order->items()->exists();

        if ($transactions->isEmpty()) {
            if ($paymentsOnly && $order->events()->where('label', 'Created from payment transactions')->exists()) {
                $order->delete();
            }

            return;
        }

        $sum = fn (string $effect) => $transactions->filter(fn ($t) => PaymentTransaction::effect($t->type) === $effect)->sum('amount');
        $attributes = [
            'channel_fees' => round($transactions->sum('fee'), 2),
            'refunds' => round(max(0, -($sum('refund') + $sum('chargeback'))), 2),
        ];
        if ($paymentsOnly) {
            // Sales are what the customer paid, without the sales tax Shopify keeps back.
            $attributes['subtotal'] = round(max(0, $sum('sale') + $sum('tax')), 2);
            $attributes['placed_at'] = $transactions->min('occurred_at');
        }

        $order->update($attributes);
    }

    /** Refresh an order's money from its payments, if it has any (used after an orders import or API sync rewrites it). */
    public static function refreshOrderIfPaid(Order $order): void
    {
        if ($order->paymentTransactions()->exists()) {
            self::refreshOrder($order);
        }
    }

    /**
     * Find the stored record for a match key, or decide that a new one is needed.
     *
     * @return array{0: mixed, 1: bool, 2: int} [existing record or null, matched, occurrence for a new record]
     */
    private static function upsert(int $channelId, $query, string $key, int $occurrence, ?StatementImport $import, bool $allowDuplicate, callable $describe): array
    {
        $existing = (clone $query)->where('channel_id', $channelId)->where('match_key', $key);

        if ($import) {
            $record = (clone $existing)->where('occurrence', $occurrence)->first();

            return [$record, (bool) $record, $occurrence];
        }

        $first = (clone $existing)->orderBy('occurrence')->first();
        if ($first && ! $allowDuplicate) {
            $origin = $first->source === 'import' ? 'imported from a statement' : 'entered manually';
            throw ValidationException::withMessages(['duplicate' => "This matches an existing {$describe($first)} ({$origin}). Tick “Add anyway” only if it's a separate transaction."]);
        }

        return [null, false, $first ? (clone $existing)->max('occurrence') + 1 : 1];
    }

    private static function sourceAttributes($record, ?StatementImport $import): array
    {
        if (! $import) {
            return $record->exists ? [] : ['source' => 'manual', 'entered_manually' => true];
        }

        // A record keeps the upload that first added it, so undoing a later, overlapping upload doesn't remove it.
        return ['source' => 'import', 'statement_import_id' => $record->statement_import_id ?? $import->id, 'entered_manually' => (bool) $record->entered_manually];
    }

    /** Attach the transaction to its order or expense, according to its type. */
    private static function link(PaymentTransaction $transaction): void
    {
        $effect = PaymentTransaction::effect($transaction->type);

        if (str_starts_with($effect, 'expense:')) {
            self::syncExpense($transaction, substr($effect, 8));

            return;
        }

        $previousOrder = $transaction->order;
        $order = in_array($effect, ['sale', 'tax', 'refund', 'chargeback'], true) && $transaction->order_ref
            ? self::orderFor($transaction)
            : null;
        $transaction->update(['order_id' => $order?->id]);

        foreach (array_filter([$order, $previousOrder?->id !== $order?->id ? $previousOrder : null]) as $affected) {
            self::touch($affected);
        }
    }

    /** Refresh an order now, or once at the end when inside a batch. */
    private static function touch(Order $order): void
    {
        if (self::$deferred !== null) {
            self::$deferred[$order->id] = $order;
        } else {
            self::refreshOrder($order);
        }
    }

    /** Orders waiting to be refreshed while a batch runs, or null when refreshing straight away. */
    private static ?array $deferred = null;

    /** Run a batch (e.g. an import of thousands of rows), refreshing each affected order once at the end. */
    public static function batch(callable $callback): mixed
    {
        $outer = self::$deferred === null;
        self::$deferred ??= [];
        try {
            $result = $callback();
        } finally {
            if ($outer) {
                [$orders, self::$deferred] = [self::$deferred, null];
            }
        }
        foreach ($outer ? $orders : [] as $order) {
            self::refreshOrder($order);
        }

        return $result;
    }

    private static function orderFor(PaymentTransaction $transaction): Order
    {
        $channel = $transaction->channel;
        $refs = [$transaction->order_ref, '#'.$transaction->order_ref];

        $order = $channel->orders()->where(fn ($q) => $q->whereIn('number', $refs)->orWhereIn('external_id', $refs))->first();
        if ($order) {
            return $order;
        }

        $order = $channel->user->orders()->create([
            'channel_id' => $channel->id,
            'external_id' => '#'.$transaction->order_ref,
            'number' => '#'.$transaction->order_ref,
            'customer_name' => "{$channel->platform} customer",
            'status' => 'shipped',
            'subtotal' => 0,
            'placed_at' => $transaction->occurred_at,
        ]);
        $order->events()->create(['label' => 'Created from payment transactions', 'created_at' => $transaction->occurred_at]);

        return $order;
    }

    private static function syncExpense(PaymentTransaction $transaction, string $category): void
    {
        $attributes = [
            'channel_id' => $transaction->channel_id,
            'statement_import_id' => $transaction->statement_import_id,
            'category' => $category,
            'description' => mb_substr($transaction->type_label.($transaction->order_ref ? " · order #{$transaction->order_ref}" : '').($transaction->note ? " · {$transaction->note}" : ''), 0, 255),
            'amount' => round(-$transaction->net, 2),
            'incurred_on' => $transaction->occurred_at->toDateString(),
        ];

        // The same cost may already have been typed in on the expenses page: link to it rather than adding it twice.
        $expense = $transaction->expense ?? Expense::query()
            ->where('user_id', $transaction->user_id)->where('channel_id', $transaction->channel_id)
            ->where('category', $category)->where('amount', $attributes['amount'])->whereDate('incurred_on', $attributes['incurred_on'])
            ->whereNull('external_ref')->whereDoesntHave('paymentTransaction')
            ->first();

        $attributes['external_ref'] = "txn:{$transaction->id}";
        if ($expense) {
            $expense->update($attributes);
        } else {
            $expense = $transaction->channel->user->expenses()->create($attributes);
        }

        $transaction->update(['expense_id' => $expense->id, 'order_id' => null]);
    }

    /** Paid payouts are cash received: debit cash, credit retained earnings (net sales after fees, refunds and tax). */
    public static function postPayout(Payout $payout): void
    {
        $shouldPost = $payout->status === 'paid' && $payout->total != 0.0;
        $changed = $payout->wasRecentlyCreated || $payout->wasChanged(['total', 'status', 'paid_on']);

        if ($payout->journal_entry_id && ($changed || ! $shouldPost)) {
            $payout->journalEntry?->delete();
            $payout->update(['journal_entry_id' => null]);
        }
        if (! $shouldPost || $payout->journal_entry_id) {
            return;
        }

        $user = $payout->channel->user;
        Ledger::setupChartOfAccounts($user);
        $cash = Ledger::account($user, '1000')->id;
        $earnings = Ledger::account($user, '3100')->id;
        $amount = abs($payout->total);
        [$debit, $credit] = $payout->total > 0 ? [$cash, $earnings] : [$earnings, $cash];

        $entry = Ledger::post($user, $payout->paid_on->toDateString(), "{$payout->channel->name} payout".($payout->bank_reference ? " · {$payout->bank_reference}" : ''), [
            ['ledger_account_id' => $debit, 'debit' => $amount],
            ['ledger_account_id' => $credit, 'credit' => $amount],
        ], "PO-{$payout->id}");
        $payout->update(['journal_entry_id' => $entry->id]);
    }
}
