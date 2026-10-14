<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Services\CashFlow;
use App\Services\Csv;
use App\Services\Ledger;
use App\Services\ProfitLoss;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public function profitLoss(Request $request): array
    {
        [$period, $group, $channelIds, $filter] = $this->statementParams($request);

        return ProfitLoss::build($request->user(), $period, $group, $channelIds) + ['filter' => $filter];
    }

    public function exportProfitLoss(Request $request): StreamedResponse
    {
        [$period, $group, $channelIds, $filter] = $this->statementParams($request);
        $statement = ProfitLoss::build($request->user(), $period, $group, $channelIds);
        $name = $filter ? '-'.Str::slug($filter) : '';

        return Csv::download("northstar-profit-loss{$name}-{$statement['period']['from']}.csv", ProfitLoss::toCsv($statement));
    }

    public function cashFlow(Request $request): array
    {
        [$period, $group, $store, $method] = $this->cashFlowParams($request);

        return CashFlow::build($request->user(), $period, $group, $store, $method);
    }

    public function exportCashFlow(Request $request): StreamedResponse
    {
        [$period, $group, $store, $method] = $this->cashFlowParams($request);
        $statement = CashFlow::build($request->user(), $period, $group, $store, $method);
        $name = $store ? '-'.Str::slug($store->name) : '';

        return Csv::download("northstar-cash-flow{$name}-{$statement['period']['from']}.csv", CashFlow::toCsv($statement));
    }

    public function balanceSheet(Request $request): array
    {
        $request->validate(['as_of' => ['nullable', 'date']]);

        return Ledger::balanceSheet($request->user(), $this->asOf($request));
    }

    public function accountEntries(Request $request, int $id): array
    {
        $account = $request->user()->ledgerAccounts()->findOrFail($id);

        return [
            'account' => $account->only('id', 'name', 'detail', 'type'),
            'entries' => Ledger::entriesFor($account, $this->asOf($request)),
        ];
    }

    public function accounts(Request $request)
    {
        return $request->user()->ledgerAccounts()->orderBy('type')->orderBy('parent_id')->orderBy('sort')->get(['id', 'parent_id', 'type', 'code', 'name']);
    }

    public function journalIndex(Request $request)
    {
        return $request->user()->journalEntries()
            ->with('lines.account:id,name')
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));
    }

    public function journalStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.ledger_account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $entry = Ledger::post($request->user(), $data['date'], $data['description'], $data['lines']);

        return response()->json($entry, 201);
    }

    private function statementParams(Request $request): array
    {
        $request->validate([
            'period' => ['nullable', 'string', fn ($attribute, $value, $fail) => ProfitLoss::isValidPeriod($value) || $fail('Choose a period, a year or a range of months.')],
            'group' => ['nullable', Rule::in(ProfitLoss::GROUPS)],
            'channel_id' => ['nullable', 'integer'],
            'platform' => ['nullable', Rule::in(array_keys(Channel::SUPPORTED))],
        ]);

        // One store, or every store on a platform; null means the whole business.
        [$channelIds, $filter] = [null, null];
        if ($request->filled('channel_id')) {
            $store = $request->user()->channels()->findOrFail($request->integer('channel_id'));
            [$channelIds, $filter] = [[$store->id], $store->name];
        } elseif ($request->filled('platform')) {
            $platform = $request->input('platform');
            [$channelIds, $filter] = [$request->user()->channels()->where('platform', $platform)->pluck('id')->all(), "All {$platform} stores"];
        }

        return [$request->input('period', 'this_month'), $request->input('group', 'channel'), $channelIds, $filter];
    }

    private function cashFlowParams(Request $request): array
    {
        $request->validate([
            'period' => ['nullable', 'string', fn ($attribute, $value, $fail) => ProfitLoss::isValidPeriod($value) || $fail('Choose a period, a year or a range of months.')],
            'group' => ['nullable', Rule::in(CashFlow::GROUPS)],
            'channel_id' => ['nullable', 'integer'],
            'method' => ['nullable', Rule::in(CashFlow::METHODS)],
        ]);
        $store = $request->filled('channel_id') ? $request->user()->channels()->findOrFail($request->integer('channel_id')) : null;

        return [$request->input('period', 'last_6_months'), $request->input('group', 'month'), $store, $request->input('method', 'indirect')];
    }

    private function asOf(Request $request): string
    {
        return $request->date('as_of')?->toDateString() ?? now()->toDateString();
    }
}
