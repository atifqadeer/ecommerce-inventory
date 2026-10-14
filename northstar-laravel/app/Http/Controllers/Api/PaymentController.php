<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Models\Payout;
use App\Services\PaymentLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Payment transactions and payouts: listing, and manual entry that can't duplicate imported records. */
class PaymentController extends Controller
{
    public function transactions(Request $request): array
    {
        $query = $request->user()->paymentTransactions()
            ->when($request->filled('channel_id'), fn ($q) => $q->where('channel_id', $request->integer('channel_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('search'), fn ($q) => $q->where('order_ref', 'like', '%'.ltrim($request->string('search')->trim(), '#').'%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('occurred_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('occurred_at', '<=', $request->date('to')));

        $summary = (clone $query)->groupBy('type')->selectRaw('type, COUNT(*) as count, SUM(amount) as amount, SUM(fee) as fee, SUM(net) as net')->get()
            ->map(fn ($row) => ['type' => $row->type, 'label' => PaymentTransaction::TYPES[$row->type][0] ?? $row->type, 'count' => (int) $row->count,
                'amount' => round((float) $row->amount, 2), 'fee' => round((float) $row->fee, 2), 'net' => round((float) $row->net, 2)])
            ->sortByDesc(fn ($row) => abs($row['amount']))->values();

        $page = $query->with(['channel:id,name,platform,color', 'order:id,number'])
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        return $page->toArray() + ['summary' => $summary];
    }

    public function storeTransaction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(array_keys(PaymentTransaction::TYPES))],
            'order_ref' => ['nullable', 'string', 'max:64'],
            'occurred_at' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'not_in:0'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'payout_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'allow_duplicate' => ['boolean'],
        ]);
        $channel = $request->user()->channels()->findOrFail($data['channel_id']);

        [$transaction] = DB::transaction(fn () => PaymentLedger::saveTransaction($channel, [
            'type' => $data['type'],
            'order_ref' => $data['order_ref'] ?? null,
            'occurred_at' => Carbon::parse($data['occurred_at']),
            'amount' => PaymentTransaction::signed($data['type'], (float) $data['amount']),
            'fee' => (float) ($data['fee'] ?? 0),
            'payout_date' => $data['payout_date'] ?? null,
            'note' => $data['note'] ?? null,
        ], allowDuplicate: $request->boolean('allow_duplicate')));

        return response()->json($transaction->load(['channel:id,name,platform,color', 'order:id,number']), 201);
    }

    public function destroyTransaction(Request $request, int $id): JsonResponse
    {
        PaymentLedger::deleteTransaction($request->user()->paymentTransactions()->findOrFail($id));

        return response()->json(['message' => 'Transaction deleted.']);
    }

    public function payouts(Request $request)
    {
        return $request->user()->payouts()
            ->when($request->filled('channel_id'), fn ($q) => $q->where('channel_id', $request->integer('channel_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with('channel:id,name,platform,color')
            ->orderByDesc('paid_on')->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));
    }

    public function storePayout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel_id' => ['required', 'integer'],
            'paid_on' => ['required', 'date'],
            'status' => ['required', Rule::in(Payout::STATUSES)],
            'total' => ['required', 'numeric', 'not_in:0'],
            'charges' => ['nullable', 'numeric'],
            'refunds' => ['nullable', 'numeric'],
            'adjustments' => ['nullable', 'numeric'],
            'tax' => ['nullable', 'numeric'],
            'fees' => ['nullable', 'numeric', 'min:0'],
            'bank_reference' => ['nullable', 'string', 'max:64'],
            'allow_duplicate' => ['boolean'],
        ]);
        $channel = $request->user()->channels()->findOrFail($data['channel_id']);

        [$payout] = DB::transaction(fn () => PaymentLedger::savePayout($channel, [
            'paid_on' => Carbon::parse($data['paid_on'])->toDateString(),
            'status' => $data['status'],
            'total' => (float) $data['total'],
            'charges' => (float) ($data['charges'] ?? 0),
            'refunds' => -abs((float) ($data['refunds'] ?? 0)),
            'adjustments' => (float) ($data['adjustments'] ?? 0),
            'tax' => (float) ($data['tax'] ?? 0),
            'fees' => (float) ($data['fees'] ?? 0),
            'bank_reference' => $data['bank_reference'] ?? null,
        ], allowDuplicate: $request->boolean('allow_duplicate')));

        return response()->json($payout->load('channel:id,name,platform,color'), 201);
    }

    /** Change a payout's status, e.g. scheduled → paid once it reaches the bank. */
    public function updatePayout(Request $request, int $id): Payout
    {
        $payout = $request->user()->payouts()->findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in(Payout::STATUSES)]]);

        DB::transaction(function () use ($payout, $data) {
            $payout->update(['status' => $data['status']]);
            PaymentLedger::postPayout($payout);
        });

        return $payout->fresh()->load('channel:id,name,platform,color');
    }

    public function destroyPayout(Request $request, int $id): JsonResponse
    {
        PaymentLedger::deletePayout($request->user()->payouts()->findOrFail($id));

        return response()->json(['message' => 'Payout deleted.']);
    }
}
