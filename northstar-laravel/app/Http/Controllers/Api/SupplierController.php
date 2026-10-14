<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $suppliers = $request->user()->suppliers()->orderBy('name')
            ->withCount(['purchaseOrders as open_orders_count' => fn ($q) => $q->whereIn('status', PurchaseOrder::OPEN_STATUSES)])
            ->with(['purchaseOrders' => fn ($q) => $q->where('status', '!=', 'cancelled')->with(['items', 'payments'])])
            ->get();

        // What's still owed to each supplier across its orders.
        return $suppliers->map(fn (Supplier $s) => $s->only(['id', 'name', 'contact_name', 'email', 'phone', 'address', 'currency', 'payment_terms_days', 'lead_time_days', 'notes', 'open_orders_count'])
            + ['balance_due' => round($s->purchaseOrders->sum('balance_due'), 2), 'orders_count' => $s->purchaseOrders->count()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($request->user()->suppliers()->create($this->validated($request)), 201);
    }

    public function update(Request $request, int $id): Supplier
    {
        $supplier = $request->user()->suppliers()->findOrFail($id);
        $supplier->update($this->validated($request, $supplier));

        return $supplier;
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $supplier = $request->user()->suppliers()->findOrFail($id);
        if ($supplier->purchaseOrders()->exists()) {
            throw ValidationException::withMessages(['supplier' => "{$supplier->name} has purchase orders, so it can't be deleted."]);
        }
        $supplier->delete();

        return response()->json(['message' => 'Supplier deleted.']);
    }

    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers')->where('user_id', $request->user()->id)->ignore($supplier?->id)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:1000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['name.unique' => 'You already have a supplier with this name.']);
    }
}
