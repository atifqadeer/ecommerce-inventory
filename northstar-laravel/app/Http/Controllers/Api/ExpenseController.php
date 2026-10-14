<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->expenses()
            ->with('channel:id,name,platform,color')
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->orderByDesc('incurred_on')->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(Expense::CATEGORIES))],
            'channel_id' => ['nullable', 'integer', Rule::exists('channels', 'id')->where('user_id', $request->user()->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'incurred_on' => ['required', 'date'],
            'allow_duplicate' => ['boolean'],
        ]);

        // The same cost may already be here, typed in earlier or imported from a statement.
        $existing = $request->user()->expenses()
            ->where('channel_id', $data['channel_id'] ?? null)->where('category', $data['category'])
            ->where('amount', round($data['amount'], 2))->whereDate('incurred_on', $data['incurred_on'])
            ->first();
        if ($existing && ! $request->boolean('allow_duplicate')) {
            $origin = $existing->statement_import_id || $existing->external_ref ? 'imported from a statement' : 'entered manually';
            throw ValidationException::withMessages(['duplicate' => "An identical expense on this date already exists ({$origin}: {$existing->description}). Tick “Add anyway” only if it's a separate cost."]);
        }
        unset($data['allow_duplicate']);

        return response()->json($request->user()->expenses()->create($data)->load('channel:id,name,platform,color'), 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $expense = $request->user()->expenses()->findOrFail($id);
        if ($expense->paymentTransaction()->exists()) {
            throw ValidationException::withMessages(['expense' => 'This cost comes from a payment transaction. Delete the transaction on the Payments page instead.']);
        }
        $expense->delete();

        return response()->json(['message' => 'Expense deleted.']);
    }
}
