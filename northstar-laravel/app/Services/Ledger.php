<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Double-entry ledger: chart of accounts, posting journal entries, and balances as of a date.
 */
class Ledger
{
    public const RECEIVABLES_CODE = '1100';

    private const CHART = [
        ['asset', '1000', 'Cash', 'Operating cash accounts'],
        ['asset', self::RECEIVABLES_CODE, 'Marketplace receivables', 'Amounts owed by connected channels'],
        ['asset', '1200', 'Inventory value', 'Weighted average cost across warehouses'],
        ['asset', '1500', 'Other assets', 'Prepaid expenses and deposits'],
        ['liability', '2000', 'Supplier payables', 'Open bills and purchase orders'],
        ['liability', '2100', 'Sales tax payable', 'Collected tax awaiting remittance'],
        ['equity', '3000', "Owner's equity", 'Capital contributions'],
        ['equity', '3100', 'Retained earnings', 'Cumulative profits less distributions'],
    ];

    public static function setupChartOfAccounts(User $user): void
    {
        if ($user->ledgerAccounts()->exists()) {
            return;
        }

        foreach (self::CHART as $i => [$type, $code, $name, $detail]) {
            $user->ledgerAccounts()->create(compact('type', 'code', 'name', 'detail') + ['sort' => $i]);
        }
    }

    public static function account(User $user, string $code): LedgerAccount
    {
        return $user->ledgerAccounts()->where('code', $code)->firstOrFail();
    }

    /** Each channel gets its own sub-account under marketplace receivables. */
    public static function receivableAccountFor(Channel $channel): LedgerAccount
    {
        $parent = self::account($channel->user, self::RECEIVABLES_CODE);

        return $channel->user->ledgerAccounts()->firstOrCreate(
            ['parent_id' => $parent->id, 'name' => "{$channel->name} receivables"],
            ['type' => 'asset', 'detail' => "Settlements owed by {$channel->name}", 'sort' => $parent->children()->count()],
        );
    }

    /**
     * @param  array<int, array{ledger_account_id:int, debit?:float, credit?:float}>  $lines
     */
    public static function post(User $user, string $date, string $description, array $lines, ?string $reference = null): JournalEntry
    {
        $debits = round(array_sum(array_column($lines, 'debit')), 2);
        $credits = round(array_sum(array_column($lines, 'credit')), 2);

        if ($debits <= 0 || $debits !== $credits) {
            throw ValidationException::withMessages(['lines' => "Debits ($debits) must equal credits ($credits) and be greater than zero."]);
        }

        $accountIds = $user->ledgerAccounts()->pluck('id');
        foreach ($lines as $line) {
            if (! $accountIds->contains($line['ledger_account_id'])) {
                throw ValidationException::withMessages(['lines' => 'Unknown ledger account.']);
            }
        }

        return DB::transaction(function () use ($user, $date, $description, $lines, $reference) {
            $entry = $user->journalEntries()->create([
                'reference' => $reference ?? self::nextReference($user),
                'date' => $date,
                'description' => $description,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'ledger_account_id' => $line['ledger_account_id'],
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                ]);
            }

            return $entry->load('lines.account');
        });
    }

    public static function nextReference(User $user): string
    {
        return 'JE-'.(1001 + $user->journalEntries()->count());
    }

    /**
     * Signed balance of every account (own postings only) as of the given date, keyed by account id.
     *
     * @return Collection<int, float>
     */
    public static function balances(User $user, string $asOf): Collection
    {
        $accounts = $user->ledgerAccounts()->get()->keyBy('id');

        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.user_id', $user->id)
            ->whereDate('journal_entries.date', '<=', $asOf)
            ->groupBy('ledger_account_id')
            ->selectRaw('ledger_account_id, SUM(debit) as debit, SUM(credit) as credit')
            ->get()
            ->mapWithKeys(function ($row) use ($accounts) {
                $account = $accounts[$row->ledger_account_id];
                $net = $account->isDebitNormal() ? $row->debit - $row->credit : $row->credit - $row->debit;

                return [$row->ledger_account_id => round($net, 2)];
            });
    }

    /** Balance sheet grouped by type, with sub-accounts rolled up into their parents. */
    public static function balanceSheet(User $user, string $asOf): array
    {
        $balances = self::balances($user, $asOf);
        $accounts = $user->ledgerAccounts()->whereNull('parent_id')->with('children')->orderBy('sort')->get();

        $rows = $accounts->map(function (LedgerAccount $account) use ($balances) {
            $children = $account->children->map(fn (LedgerAccount $child) => [
                'id' => $child->id,
                'name' => $child->name,
                'detail' => $child->detail,
                'balance' => $balances[$child->id] ?? 0.0,
            ])->values();

            return [
                'id' => $account->id,
                'type' => $account->type,
                'code' => $account->code,
                'name' => $account->name,
                'detail' => $account->detail,
                'balance' => round(($balances[$account->id] ?? 0) + $children->sum('balance'), 2),
                'children' => $children,
            ];
        });

        $total = fn (string $type) => round($rows->where('type', $type)->sum('balance'), 2);
        $assets = $total('asset');
        $liabilities = $total('liability');
        $equity = $total('equity');

        return [
            'as_of' => $asOf,
            'assets' => $rows->where('type', 'asset')->values(),
            'liabilities_equity' => $rows->whereIn('type', ['liability', 'equity'])->values(),
            'totals' => ['assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity],
            'difference' => round($assets - $liabilities - $equity, 2),
            'balanced' => abs($assets - $liabilities - $equity) < 0.01,
        ];
    }

    /** Journal entries that touched an account (or its sub-accounts) up to a date. */
    public static function entriesFor(LedgerAccount $account, string $asOf): Collection
    {
        $ids = $account->children()->pluck('id')->push($account->id);

        return JournalEntry::query()
            ->where('user_id', $account->user_id)
            ->whereDate('date', '<=', $asOf)
            ->whereHas('lines', fn ($q) => $q->whereIn('ledger_account_id', $ids))
            ->with(['lines' => fn ($q) => $q->whereIn('ledger_account_id', $ids)])
            ->orderByDesc('date')->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (JournalEntry $entry) => [
                'id' => $entry->id,
                'reference' => $entry->reference,
                'date' => $entry->date->format('Y-m-d'),
                'description' => $entry->description,
                'debit' => round($entry->lines->sum('debit'), 2),
                'credit' => round($entry->lines->sum('credit'), 2),
            ]);
    }
}
