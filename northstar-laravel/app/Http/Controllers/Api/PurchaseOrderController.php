<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\Purchasing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): array
    {
        $orders = $request->user()->purchaseOrders()
            ->when($request->filled('status'), fn ($q) => $request->input('status') === 'open'
                ? $q->whereIn('status', ['draft', ...PurchaseOrder::OPEN_STATUSES])
                : $q->where('status', $request->input('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('number', 'like', '%'.$request->string('search')->trim().'%')
                ->orWhereHas('items', fn ($i) => $i->where('sku', 'like', '%'.$request->string('search')->trim().'%'))))
            ->with(['supplier:id,name', 'items', 'payments'])
            ->latest('id')
            ->get();

        $open = $request->user()->purchaseOrders()->whereIn('status', PurchaseOrder::OPEN_STATUSES)->with('items')->get();
        $unpaid = $request->user()->purchaseOrders()->where('status', '!=', 'cancelled')->with(['items', 'payments'])->get();

        return [
            'data' => $orders,
            'summary' => [
                'open_orders' => $open->count(),
                // Value of units ordered but not yet arrived.
                'on_order' => round($open->sum(fn ($o) => $o->items->sum(fn ($i) => ($i->quantity - $i->quantity_received) * $i->unit_cost)), 2),
                'owed_to_suppliers' => round($unpaid->sum('balance_due'), 2),
                'overdue' => $open->filter(fn ($o) => $o->expected_on && $o->expected_on->isPast() && ! $o->expected_on->isToday())->count(),
            ],
        ];
    }

    public function show(Request $request, int $id): PurchaseOrder
    {
        return $request->user()->purchaseOrders()
            ->with(['supplier', 'items.product:id,sku,name,on_hand,unit_cost', 'receipts.lines.item:id,sku,name', 'payments'])
            ->findOrFail($id);
    }

    public function store(Request $request): JsonResponse
    {
        $order = Purchasing::create($request->user(), $this->validated($request));

        return response()->json($this->show($request, $order->id), 201);
    }

    public function update(Request $request, int $id): PurchaseOrder
    {
        Purchasing::update($request->user()->purchaseOrders()->findOrFail($id), $this->validated($request));

        return $this->show($request, $id);
    }

    public function order(Request $request, int $id): PurchaseOrder
    {
        $request->validate(['ordered_on' => ['nullable', 'date']]);
        Purchasing::markOrdered($request->user()->purchaseOrders()->findOrFail($id), $request->input('ordered_on'));

        return $this->show($request, $id);
    }

    public function receive(Request $request, int $id): PurchaseOrder
    {
        $data = $request->validate([
            'received_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'integer', 'min:0'],
        ]);
        Purchasing::receive($request->user()->purchaseOrders()->findOrFail($id), $data['items'], $data['received_on'] ?? null, $data['note'] ?? null);

        return $this->show($request, $id);
    }

    public function cancel(Request $request, int $id): PurchaseOrder
    {
        Purchasing::cancel($request->user()->purchaseOrders()->with(['items', 'payments'])->findOrFail($id));

        return $this->show($request, $id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        Purchasing::delete($request->user()->purchaseOrders()->findOrFail($id));

        return response()->json(['message' => 'Purchase order deleted.']);
    }

    public function storePayment(Request $request, int $id): PurchaseOrder
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'max:64'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);
        $order = $request->user()->purchaseOrders()->with(['items', 'payments'])->findOrFail($id);
        Purchasing::addPayment($order, (float) $data['amount'], $data['paid_on'] ?? null, $data['method'] ?? null, $data['reference'] ?? null);

        return $this->show($request, $id);
    }

    public function destroyPayment(Request $request, int $id, int $paymentId): PurchaseOrder
    {
        Purchasing::deletePayment($request->user()->purchaseOrders()->findOrFail($id)->payments()->findOrFail($paymentId));

        return $this->show($request, $id);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('user_id', $request->user()->id)],
            'expected_on' => ['nullable', 'date'],
            'warehouse' => ['nullable', 'string', 'max:255'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'other_costs' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ], ['items.*.product_id.distinct' => 'Each product can only be on the order once.']);
    }
}
