<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\JournalEntry;
use App\Models\PaymentTransaction;
use App\Models\PurchaseReceipt;
use App\Models\StockMovement;
use App\Models\SupplierPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Cash flow statement, by the indirect method (starting from net profit, the default) or the direct method
 * (receipts and payments). Either way the cash itself is built from what actually moved money:
 *
 *  - stores with payouts: cash arrives when a payout is paid, split by the payout's own columns;
 *  - stores with payment transactions but no payouts: each transaction's net, dated by its payout date;
 *  - stores with neither: estimated from orders (sales less fees, refunds and shipping) on the order date;
 *  - operating expenses typed in, stock received (at unit cost) and journal entries that touch Cash.
 *
 * Costs a marketplace deducts before paying out (fees, Shop campaigns, statement charges) are already inside
 * the payout or settlement, so they are never counted a second time as expenses. Opening cash is the sum of
 * every flow before the period, so opening + net change = closing.
 *
 * The indirect method explains the same operating cash starting from the P&L's net profit: add back cost of
 * goods sold, subtract stock bought, subtract the growth in what marketplaces owe, and show any remaining gap
 * between recorded sales and what was actually settled as "other timing differences".
 */
class CashFlow
{
    public const SECTIONS = [
        'operating' => ['Operating activities', [
            'customer_payments' => 'Customer payments collected',
            'payouts' => 'Marketplace payouts (not itemised)',
            'refunds' => 'Refunds & chargebacks',
            'tax_withheld' => 'Sales tax withheld by marketplace',
            'fees' => 'Payment processing & marketplace fees',
            'marketplace_charges' => 'Marketplace charges & adjustments',
            'shipping' => 'Shipping & fulfilment',
            'expenses' => 'Operating expenses paid',
            'suppliers' => 'Paid to suppliers (buying stock)',
            'inventory' => 'Other stock bought',
            'sales_tax' => 'Sales tax paid',
        ]],
        'investing' => ['Investing activities', [
            'assets' => 'Other assets',
        ]],
        'financing' => ['Financing activities', [
            'owner' => "Owner contributions & draws",
        ]],
    ];

    public const GROUPS = ['month', 'channel'];

    public const METHODS = ['indirect', 'direct'];

    /** @param  ?Channel  $store  limit the statement to one store's own cash (its payouts, transactions and expenses) */
    public static function build(User $user, string $period = 'last_6_months', string $group = 'month', ?Channel $store = null, string $method = 'indirect'): array
    {
        [$from, $to] = ProfitLoss::ranges($period);
        $from = $from->startOfDay();
        $to = $to->endOfDay();
        $group = $store ? 'month' : $group;

        [$flows, $notes] = self::flows($user, $to, $store);
        if ($store) {
            $shared = $flows->whereNull('channel_id')->filter(fn ($f) => $f['date'] >= $from->toDateString());
            $flows = $flows->where('channel_id', $store->id)->values();
            if ($shared->isNotEmpty()) {
                $notes[] = sprintf('Not included: %s of cash movements shared across stores (journal entries, stock received, expenses without a store). See “All stores” for the full picture.',
                    number_format(abs($shared->sum('amount')), 2));
            }
        }
        $before = $flows->filter(fn ($f) => $f['date'] < $from->toDateString());
        $during = $flows->filter(fn ($f) => $f['date'] >= $from->toDateString());

        $columns = self::columns($user, $group, $from, $to);
        $columnOf = fn (array $f) => $group === 'month' ? substr($f['date'], 0, 7) : (string) ($f['channel_id'] ?? 'shared');
        $values = fn (Collection $items) => array_map(
            fn ($c) => round($items->filter(fn ($f) => $columnOf($f) === $c['key'])->sum('amount'), 2),
            $columns,
        );
        $row = fn (string $key, string $label, Collection $items) => [
            'key' => $key, 'label' => $label, 'values' => $values($items), 'total' => round($items->sum('amount'), 2),
        ];

        $sections = [];
        foreach (self::SECTIONS as $sectionKey => [$sectionLabel, $lines]) {
            $sectionFlows = $during->filter(fn ($f) => isset($lines[$f['line']]));
            $rows = [];
            foreach ($lines as $lineKey => $lineLabel) {
                $lineFlows = $sectionFlows->where('line', $lineKey);
                if ($lineFlows->isEmpty()) {
                    continue;
                }
                // Break a line down by source or category when it has more than one.
                $children = $lineFlows->groupBy(fn ($f) => $f['detail'] ?: 'Other');
                $rows[] = $row($lineKey, $lineLabel, $lineFlows) + [
                    'children' => $children->count() > 1
                        ? $children->map(fn ($items, $detail) => $row("{$lineKey}:{$detail}", $detail, $items))->sortBy(fn ($r) => -abs($r['total']))->values()->all()
                        : [],
                ];
            }
            $sections[] = ['key' => $sectionKey, 'label' => $sectionLabel, 'lines' => $rows] + $row("net_{$sectionKey}", "Net cash from {$sectionLabel}", $sectionFlows);
        }

        $opening = round($before->sum('amount'), 2);
        $net = round($during->sum('amount'), 2);

        $receivables = Receivables::build($user, $from, $to, $columns, $columnOf, $store);
        if ($method === 'indirect') {
            [$sections[0], $indirectNotes] = self::indirectOperating($user, $from, $to, $group, $columns, $store, $during, $receivables, $sections[0], $row);
            $notes = array_merge($notes, $indirectNotes);
        }
        $months = self::months($during, $from, $to, $opening);
        $owed = $receivables['opening'];
        foreach ($months as &$month) {
            $owed = $receivables['month_ends'][$month['key']] ?? $owed;
            $month['receivable'] = $owed;
        }
        unset($month);

        return [
            'period' => [
                'key' => $period,
                'label' => ProfitLoss::label($period),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'group' => $group,
            'method' => $method,
            'store' => $store?->only(['id', 'name', 'platform', 'handle', 'currency']),
            'columns' => $columns,
            'sections' => $sections,
            'net_change' => $row('net_change', 'Net change in cash', $during),
            'summary' => [
                'opening' => $opening,
                'inflow' => round($during->where('amount', '>', 0)->sum('amount'), 2),
                'outflow' => round($during->where('amount', '<', 0)->sum('amount'), 2),
                'net' => $net,
                'closing' => round($opening + $net, 2),
                'pending_payouts' => round((float) $user->payouts()->when($store, fn ($q) => $q->where('channel_id', $store->id))->whereIn('status', ['scheduled', 'in_transit'])->sum('total'), 2),
            ],
            'months' => $months,
            'receivables' => $receivables,
            'cash_in_hand' => self::cashInHand($user, $during, $row, $opening, $net, $receivables, $store),
            'notes' => $notes,
        ];
    }

    /**
     * Operating activities by the indirect method: net profit, adjusted to the operating cash the direct
     * method measured. The section's total is the direct operating cash, so both methods agree.
     *
     * @return array{0: array, 1: array<int, string>}
     */
    private static function indirectOperating(User $user, CarbonImmutable $from, CarbonImmutable $to, string $group, array $columns, ?Channel $store,
        Collection $during, array $receivables, array $direct, callable $row): array
    {
        $keys = array_column($columns, 'key');
        $line = function (string $key, string $label, array $byColumn, array $children = []) use ($keys) {
            $values = array_map(fn ($k) => round($byColumn[$k] ?? 0, 2), $keys);

            return ['key' => $key, 'label' => $label, 'values' => $values, 'total' => round(array_sum($byColumn), 2), 'children' => $children];
        };
        $add = function (array &$byColumn, string $column, float $amount) {
            $byColumn[$column] = ($byColumn[$column] ?? 0) + $amount;
        };

        // Net profit, from the same orders and expenses as the P&L.
        $parts = ['sales' => [], 'fees' => [], 'shipping' => [], 'refunds' => [], 'cogs' => [], 'expenses' => []];
        $orders = $user->orders()->when($store, fn ($q) => $q->where('channel_id', $store->id))
            ->where('status', '!=', 'cancelled')->whereBetween('placed_at', [$from, $to])
            ->get(['channel_id', 'placed_at', 'subtotal', 'channel_fees', 'shipping_cost', 'refunds', 'cogs']);
        foreach ($orders as $o) {
            $column = $group === 'month' ? $o->placed_at->format('Y-m') : (string) ($o->channel_id ?? 'shared');
            $add($parts['sales'], $column, $o->subtotal);
            $add($parts['fees'], $column, -$o->channel_fees);
            $add($parts['shipping'], $column, -$o->shipping_cost);
            $add($parts['refunds'], $column, -$o->refunds);
            $add($parts['cogs'], $column, -$o->cogs);
        }
        $expenses = $user->expenses()->when($store, fn ($q) => $q->where('channel_id', $store->id))
            ->whereDate('incurred_on', '>=', $from->toDateString())->whereDate('incurred_on', '<=', $to->toDateString())->get();
        foreach ($expenses as $e) {
            $add($parts['expenses'], $group === 'month' ? $e->incurred_on->format('Y-m') : (string) ($e->channel_id ?? 'shared'), -$e->amount);
        }
        $profit = [];
        foreach ($parts as $byColumn) {
            foreach ($byColumn as $column => $amount) {
                $add($profit, $column, $amount);
            }
        }
        $labels = ['sales' => 'Sales', 'fees' => 'Marketplace fees', 'shipping' => 'Shipping', 'refunds' => 'Refunds',
            'cogs' => 'Cost of goods sold', 'expenses' => 'Operating & marketplace expenses'];
        $children = [];
        foreach ($parts as $part => $byColumn) {
            if (array_filter($byColumn, fn ($v) => abs($v) >= 0.005)) {
                $children[] = $line("net_profit:{$part}", $labels[$part], $byColumn);
            }
        }

        $flowsByColumn = function (callable $filter) use ($during, $group) {
            $byColumn = [];
            foreach ($during->filter($filter) as $f) {
                $column = $group === 'month' ? substr($f['date'], 0, 7) : (string) ($f['channel_id'] ?? 'shared');
                $byColumn[$column] = ($byColumn[$column] ?? 0) + $f['amount'];
            }

            return $byColumn;
        };

        $lines = [$line('net_profit', 'Net profit (from the P&L)', $profit, $children)];
        // Stock: what was sold (already in profit as cost of goods sold) against what was bought.
        $cogs = array_map(fn ($v) => -$v, $parts['cogs']);
        $received = $store ? [] : self::receiptsByColumn($user, $from, $to, $group);
        $otherStock = $flowsByColumn(fn ($f) => $f['line'] === 'inventory');
        $sum = function (array ...$sets) {
            $total = [];
            foreach ($sets as $set) {
                foreach ($set as $column => $amount) {
                    $total[$column] = ($total[$column] ?? 0) + $amount;
                }
            }

            return $total;
        };
        $negate = fn (array $set) => array_map(fn ($v) => -$v, $set);
        $children = fn (array $parts) => array_values(array_filter(array_map(
            fn ($p) => array_filter($p[2], fn ($v) => abs($v) >= 0.005) ? $line($p[0], $p[1], $p[2]) : null, $parts,
        )));

        // Supplier payables: stock received on credit raises what's owed; paying it lowers cash.
        $paidToSuppliers = $flowsByColumn(fn ($f) => $f['line'] === 'suppliers');

        $adjustments = [
            ['inventory_change', '(Increase) / decrease in inventory', $sum($cogs, $negate($received), $otherStock), $children([
                ['inventory_change:sold', 'Stock sold (cost of goods sold)', $cogs],
                ['inventory_change:received', 'Stock received from suppliers', $negate($received)],
                ['inventory_change:other', 'Other stock bought', $otherStock],
            ])],
            ['payables_change', 'Increase / (decrease) in supplier payables', $sum($received, $paidToSuppliers), $children([
                ['payables_change:received', 'Stock received (to be paid)', $received],
                ['payables_change:paid', 'Paid to suppliers', $paidToSuppliers],
            ])],
        ];
        if ($receivables['tracked']) {
            $change = [];
            foreach ($keys as $i => $column) {
                // Earned minus paid out is the growth in what marketplaces owe; that part of profit isn't cash yet.
                $change[$column] = -($receivables['earned']['values'][$i] + $receivables['collected']['values'][$i]);
            }
            $adjustments[] = ['receivables', '(Increase) / decrease in marketplace receivables', $change];
        }
        $adjustments[] = ['sales_tax', 'Sales tax paid', $flowsByColumn(fn ($f) => $f['line'] === 'sales_tax')];
        $adjustments[] = ['settlements', 'Settlements recorded in journal', $flowsByColumn(fn ($f) => $f['line'] === 'customer_payments' && $f['detail'] === 'Recorded in journal')];

        foreach ($adjustments as $adjustment) {
            [$key, $label, $byColumn] = $adjustment;
            $hasParts = ! empty($adjustment[3]) && count($adjustment[3]) > 0;
            if (array_filter($byColumn, fn ($v) => abs($v) >= 0.005) || $hasParts) {
                $lines[] = $line($key, $label, $byColumn, count($adjustment[3] ?? []) > 1 ? $adjustment[3] : []);
            }
        }

        // Whatever is left between profit and the cash actually moved.
        $explained = array_fill(0, count($keys), 0.0);
        foreach ($lines as $l) {
            foreach ($l['values'] as $i => $v) {
                $explained[$i] += $v;
            }
        }
        $residual = [];
        foreach ($keys as $i => $column) {
            $residual[$column] = $direct['values'][$i] - $explained[$i];
        }
        $notes = [];
        if (array_filter($residual, fn ($v) => abs($v) >= 0.01)) {
            $lines[] = $line('timing', 'Other timing differences', $residual);
            $notes[] = sprintf('Other timing differences (%s): the gap between sales and fees as recorded on orders and what was actually paid out, e.g. orders estimated before their payments were imported, shipping and taxes charged to buyers, or payouts outside the period.',
                number_format(array_sum($residual), 2));
        }

        return [['key' => 'operating', 'label' => 'Operating activities', 'lines' => $lines, 'values' => $direct['values'], 'total' => $direct['total']], $notes];
    }
    /** Cash in hand: opening balance, money in and out by what it was for, closing balance, and what's owed. */
    private static function cashInHand(User $user, Collection $during, callable $row, float $opening, float $net, array $receivables, ?Channel $store): array
    {
        $groups = [
            'sales' => ['Received from sales (after marketplace fees & refunds)', ['customer_payments', 'payouts', 'refunds', 'tax_withheld', 'fees', 'marketplace_charges', 'shipping']],
            'suppliers' => ['Paid to suppliers (buying stock)', ['suppliers', 'inventory']],
            'expenses' => ['Operating expenses paid', ['expenses']],
            'sales_tax' => ['Sales tax paid', ['sales_tax']],
            'owner' => ['Owner contributions & draws', ['owner']],
            'other' => ['Other assets', ['assets']],
        ];
        $rows = [];
        foreach ($groups as $key => [$label, $lines]) {
            $items = $during->filter(fn ($f) => in_array($f['line'], $lines, true));
            if ($items->isNotEmpty()) {
                $rows[] = $row($key, $label, $items);
            }
        }

        $owedToSuppliers = $store ? 0.0 : round($user->purchaseOrders()->where('status', '!=', 'cancelled')->with(['items', 'payments'])->get()->sum('balance_due'), 2);

        return [
            'opening' => $opening,
            'rows' => $rows,
            'closing' => round($opening + $net, 2),
            // Not cash yet: bought but unpaid, and sold but not paid out.
            'owed_to_suppliers' => max(0, $owedToSuppliers),
            'owed_by_marketplaces' => $receivables['tracked'] ? $receivables['current'] : null,
        ];
    }

    public static function toCsv(array $statement): string
    {
        $rows = [array_merge(['Line item'], array_column($statement['columns'], 'label'), ['Total'])];
        $line = fn (array $r, string $indent = '') => array_merge([$indent.$r['label']], $r['values'], [$r['total']]);

        $rows[] = ['Opening cash', ...array_fill(0, count($statement['columns']), ''), $statement['summary']['opening']];
        foreach ($statement['sections'] as $section) {
            $rows[] = [$section['label']];
            foreach ($section['lines'] as $r) {
                $rows[] = $line($r, '  ');
                foreach ($r['children'] as $child) {
                    $rows[] = $line($child, '    ');
                }
            }
            $rows[] = $line(['label' => 'Net cash from '.strtolower($section['label'])] + $section);
        }
        $rows[] = $line($statement['net_change']);
        $rows[] = ['Closing cash', ...array_fill(0, count($statement['columns']), ''), $statement['summary']['closing']];

        $r = $statement['receivables'];
        if ($r['tracked']) {
            $rows[] = [];
            $rows[] = ['Receivables (owed by marketplaces)'];
            $rows[] = ['Opening receivables', ...array_fill(0, count($statement['columns']), ''), $r['opening']];
            $rows[] = array_merge(['  Sales earned (after fees, refunds and tax)'], $r['earned']['values'], [$r['earned']['total']]);
            $rows[] = array_merge(['  Paid out to bank'], $r['collected']['values'], [$r['collected']['total']]);
            $rows[] = ['Closing receivables', ...array_fill(0, count($statement['columns']), ''), $r['closing']];
        }

        return Csv::fromRows($rows);
    }

    /**
     * Every cash movement up to the given date.
     *
     * @return array{0: Collection<int, array{date: string, channel_id: ?int, line: string, detail: string, amount: float}>, 1: array<int, string>}
     */
    private static function flows(User $user, CarbonImmutable $to, ?Channel $store = null): array
    {
        $flows = [];
        $notes = [];
        $add = function (string $date, ?int $channelId, string $line, float $amount, string $detail = '') use (&$flows, $to) {
            if ($amount != 0.0 && $date <= $to->toDateString()) {
                $flows[] = ['date' => substr($date, 0, 10), 'channel_id' => $channelId, 'line' => $line, 'detail' => $detail, 'amount' => round($amount, 2)];
            }
        };

        $channels = $user->channels()->when($store, fn ($q) => $q->whereKey($store->id))->withCount(['payouts', 'paymentTransactions'])->get();
        $estimated = [];

        foreach ($channels as $channel) {
            if ($channel->payouts_count) {
                self::payoutFlows($channel, $add);
            } elseif ($channel->payment_transactions_count) {
                self::transactionFlows($channel, $add);
            } elseif (self::orderFlows($channel, $add)) {
                $estimated[] = $channel->name;
            }
        }

        if ($estimated) {
            $notes[] = 'Estimated from orders (no payouts or payment transactions recorded): '.implode(', ', $estimated).'. Import their payouts or payment statements for exact cash.';
        }

        // Expenses paid directly, i.e. not deducted by a marketplace from a payout or settlement.
        $expenseLabels = \App\Models\Expense::CATEGORIES;
        foreach ($user->expenses()->whereNull('external_ref')->get() as $expense) {
            $add($expense->incurred_on->toDateString(), $expense->channel_id, 'expenses', -$expense->amount, $expenseLabels[$expense->category] ?? $expense->category);
        }

        // Stock received, valued at its unit cost.
        StockMovement::query()
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('products.user_id', $user->id)
            ->where('stock_movements.reason', 'Receiving')->where('stock_movements.quantity', '>', 0)
            ->get(['stock_movements.created_at', 'stock_movements.quantity', 'products.unit_cost'])
            ->each(fn ($m) => $add($m->created_at->toDateString(), null, 'inventory', -$m->quantity * $m->unit_cost, 'Stock received (at unit cost)'));

        self::journalFlows($user, $add);
        self::supplierPaymentFlows($user, $add);

        return [collect($flows)->sortBy('date')->values(), $notes];
    }

    /** Paid payouts, split by their own columns (charges, refunds, adjustments, tax, fees). */
    private static function payoutFlows(Channel $channel, callable $add): void
    {
        foreach ($channel->payouts()->where('status', 'paid')->get() as $p) {
            $date = $p->paid_on->toDateString();
            $itemised = round($p->charges + $p->refunds + $p->adjustments + $p->tax - $p->fees, 2);

            if ($p->charges == 0.0 && $p->refunds == 0.0 && $p->adjustments == 0.0 && $p->tax == 0.0 && $p->fees == 0.0) {
                $add($date, $channel->id, 'payouts', $p->total, 'Payouts entered without a breakdown');

                continue;
            }
            $add($date, $channel->id, 'customer_payments', $p->charges, 'From payouts');
            $add($date, $channel->id, 'refunds', -abs($p->refunds), 'From payouts');
            $add($date, $channel->id, 'marketplace_charges', $p->adjustments, 'Payout adjustments');
            $add($date, $channel->id, 'tax_withheld', $p->tax, 'From payouts');
            $add($date, $channel->id, 'fees', -$p->fees, 'From payouts');
            $add($date, $channel->id, 'marketplace_charges', $p->total - $itemised, 'Other payout items');
        }
    }

    /** Payment transactions, each landing on its payout date (or its own date when that's unknown). */
    private static function transactionFlows(Channel $channel, callable $add): void
    {
        $today = CarbonImmutable::today()->toDateString();
        foreach ($channel->paymentTransactions()->get() as $t) {
            // Cash only once its payout is paid (same rule as Receivables); until then it's still owed.
            $paid = $t->payout_status === 'paid' || (! $t->payout_status && (! $t->payout_date || $t->payout_date->toDateString() <= $today));
            if (! $paid) {
                continue;
            }
            $date = ($t->payout_date ?? $t->occurred_at)->toDateString();
            $line = match (PaymentTransaction::effect($t->type)) {
                'sale' => 'customer_payments',
                'tax' => 'tax_withheld',
                'refund', 'chargeback' => 'refunds',
                default => 'marketplace_charges',
            };
            $add($date, $channel->id, $line, $t->amount, $line === 'marketplace_charges' ? $t->type_label : 'From payment transactions');
            $add($date, $channel->id, 'fees', -$t->fee, 'From payment transactions');
        }
    }

    /** No payment data: estimate settlements from orders and the charges imported from statements. */
    private static function orderFlows(Channel $channel, callable $add): bool
    {
        $orders = $channel->orders()->where('status', '!=', 'cancelled')->get(['placed_at', 'subtotal', 'channel_fees', 'shipping_cost', 'refunds']);
        foreach ($orders as $o) {
            $date = $o->placed_at->toDateString();
            $add($date, $channel->id, 'customer_payments', $o->subtotal, 'Estimated from orders');
            $add($date, $channel->id, 'fees', -$o->channel_fees, 'Estimated from orders');
            $add($date, $channel->id, 'refunds', -$o->refunds, 'Estimated from orders');
            $add($date, $channel->id, 'shipping', -$o->shipping_cost, 'Shipping labels on orders');
        }

        // Fees and charges from imported statements are deducted from the marketplace's settlement.
        $charges = $channel->user->expenses()->where('channel_id', $channel->id)->whereNotNull('external_ref')->get();
        foreach ($charges as $expense) {
            $add($expense->incurred_on->toDateString(), $channel->id, 'marketplace_charges', -$expense->amount, \App\Models\Expense::CATEGORIES[$expense->category] ?? $expense->category);
        }

        return $orders->isNotEmpty() || $charges->isNotEmpty();
    }

    /**
     * Journal entries that move Cash (payout postings excluded: payouts are counted above). Each entry is
     * classified by its largest other account: equity → owner, liability → suppliers & tax, inventory →
     * purchases, marketplace receivables → customer payments, other assets → investing.
     */
    private static function journalFlows(User $user, callable $add): void
    {
        $cash = $user->ledgerAccounts()->where('code', '1000')->value('id');
        if (! $cash) {
            return;
        }
        $accounts = $user->ledgerAccounts()->get()->keyBy('id');
        $receivables = $accounts->firstWhere('code', Ledger::RECEIVABLES_CODE)?->id;

        $entries = JournalEntry::query()
            // Payout postings are counted from the payouts themselves; identify them by their link, not their name.
            ->where('user_id', $user->id)->whereNotIn('id', $user->payouts()->whereNotNull('journal_entry_id')->select('journal_entry_id'))
            // Supplier payments are counted from purchasing, with the supplier's name.
            ->whereNotIn('id', self::supplierPayments($user)->whereNotNull('supplier_payments.journal_entry_id')->select('supplier_payments.journal_entry_id'))
            ->whereHas('lines', fn ($q) => $q->where('ledger_account_id', $cash))
            ->with('lines')->get();

        foreach ($entries as $entry) {
            $amount = $entry->lines->where('ledger_account_id', $cash)->sum(fn ($l) => $l->debit - $l->credit);
            $counter = $entry->lines->where('ledger_account_id', '!=', $cash)->sortByDesc(fn ($l) => abs($l->debit - $l->credit))->first();
            $account = $counter ? $accounts[$counter->ledger_account_id] : null;

            $line = match (true) {
                ! $account => 'assets',
                $account->type === 'equity' => 'owner',
                $account->code === '2100' => 'sales_tax',
                $account->type === 'liability' => 'suppliers',
                $account->code === '1200' => 'inventory',
                $account->id === $receivables || $account->parent_id === $receivables => 'customer_payments',
                default => 'assets',
            };
            $add($entry->date->toDateString(), null, $line, $amount, in_array($line, ['inventory', 'customer_payments', 'suppliers'], true) ? 'Recorded in journal' : ($account->name ?? ''));
        }
    }

    private static function supplierPayments(User $user)
    {
        return SupplierPayment::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'supplier_payments.purchase_order_id')
            ->where('purchase_orders.user_id', $user->id);
    }

    /** Cash paid to suppliers for purchase orders: a cash outflow on the day it's paid. */
    private static function supplierPaymentFlows(User $user, callable $add): void
    {
        self::supplierPayments($user)
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->get(['supplier_payments.paid_on', 'supplier_payments.amount', 'suppliers.name as supplier_name'])
            ->each(fn ($p) => $add($p->paid_on->toDateString(), null, 'suppliers', -$p->amount, $p->supplier_name));
    }

    /** Landed value of stock received on purchase orders, per statement column (stock bought, paid or not). */
    private static function receiptsByColumn(User $user, CarbonImmutable $from, CarbonImmutable $to, string $group): array
    {
        $byColumn = [];
        PurchaseReceipt::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_receipts.purchase_order_id')
            ->where('purchase_orders.user_id', $user->id)
            ->whereBetween('purchase_receipts.received_on', [$from->toDateString(), $to->toDateString()])
            ->get(['purchase_receipts.received_on', 'purchase_receipts.value'])
            ->each(function ($r) use (&$byColumn, $group) {
                $column = $group === 'month' ? $r->received_on->format('Y-m') : 'shared';
                $byColumn[$column] = ($byColumn[$column] ?? 0) + $r->value;
            });

        return $byColumn;
    }

    private static function columns(User $user, string $group, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($group === 'channel') {
            return $user->channels()->orderBy('platform')->orderBy('id')->get()
                ->map(fn ($c) => ['key' => (string) $c->id, 'label' => $c->name, 'color' => $c->color])
                ->push(['key' => 'shared', 'label' => 'Shared', 'color' => null])
                ->all();
        }

        return collect(CarbonPeriod::create($from->startOfMonth(), '1 month', $to))
            ->map(fn ($month) => ['key' => $month->format('Y-m'), 'label' => $month->format('M Y')])
            ->values()->all();
    }

    /** Money in, money out and the running cash balance per month, for the chart. */
    private static function months(Collection $during, CarbonImmutable $from, CarbonImmutable $to, float $opening): array
    {
        $balance = $opening;

        return collect(CarbonPeriod::create($from->startOfMonth(), '1 month', $to))->map(function ($month) use ($during, &$balance) {
            $flows = $during->filter(fn ($f) => str_starts_with($f['date'], $month->format('Y-m')));
            $balance += $flows->sum('amount');

            return [
                'key' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'inflow' => round($flows->where('amount', '>', 0)->sum('amount'), 2),
                'outflow' => round($flows->where('amount', '<', 0)->sum('amount'), 2),
                'net' => round($flows->sum('amount'), 2),
                'closing' => round($balance, 2),
            ];
        })->values()->all();
    }
}
