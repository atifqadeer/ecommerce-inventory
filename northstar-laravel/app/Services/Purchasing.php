<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buying stock: purchase orders, receiving and paying suppliers.
 *
 *  - Receiving adds the units to stock and moves each product's cost to the weighted average of what's on
 *    hand and the landed cost just received; it books Inventory against Supplier payables.
 *  - Paying a supplier books Supplier payables against Cash, so it shows in the cash flow.
 */
class Purchasing
{
    /**
     * @param  array{supplier_id: int, expected_on?: ?string, warehouse?: ?string, shipping_cost?: float, other_costs?: float, notes?: ?string,
     *               items: array<int, array{product_id: int, quantity: int, unit_cost: float}>}  $data
     */
    public static function create(User $user, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($user, $data) {
            $order = $user->purchaseOrders()->create(self::header($data) + [
                'supplier_id' => $data['supplier_id'],
                'number' => self::nextNumber($user),
                'status' => 'draft',
            ]);
            self::syncItems($user, $order, $data['items']);

            return $order;
        });
    }

    /** Edit a purchase order that isn't finished: header, prices and quantities (never below what has arrived). */
    public static function update(PurchaseOrder $order, array $data): PurchaseOrder
    {
        if (in_array($order->status, ['received', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => "A {$order->status} purchase order can't be changed."]);
        }

        return DB::transaction(function () use ($order, $data) {
            $order->update(self::header($data) + ['supplier_id' => $data['supplier_id'] ?? $order->supplier_id]);
            if (array_key_exists('items', $data)) {
                self::syncItems($order->user, $order, $data['items']);
            }
            self::refreshStatus($order);

            return $order;
        });
    }

    public static function markOrdered(PurchaseOrder $order, ?string $orderedOn = null): PurchaseOrder
    {
        if ($order->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft can be marked as ordered.']);
        }
        $date = Carbon::parse($orderedOn ?? now()->toDateString());
        $order->update([
            'status' => 'ordered',
            'ordered_on' => $date->toDateString(),
            'expected_on' => $order->expected_on ?? $date->copy()->addDays($order->supplier->lead_time_days)->toDateString(),
        ]);

        return $order;
    }

    /**
     * Receive units, all at once or in parts.
     *
     * @param  array<int, int>  $quantities  purchase order item id => units arriving now
     */
    public static function receive(PurchaseOrder $order, array $quantities, ?string $receivedOn = null, ?string $note = null): PurchaseOrder
    {
        if (in_array($order->status, ['received', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => "This purchase order is {$order->status}."]);
        }
        $quantities = array_filter(array_map('intval', $quantities), fn ($q) => $q > 0);
        if (! $quantities) {
            throw ValidationException::withMessages(['items' => 'Enter how many units arrived.']);
        }

        return DB::transaction(function () use ($order, $quantities, $receivedOn, $note) {
            if ($order->status === 'draft') {
                self::markOrdered($order, $receivedOn);
            }
            $order->load('items');
            $date = Carbon::parse($receivedOn ?? now()->toDateString())->toDateString();
            $lines = [];

            foreach ($quantities as $itemId => $quantity) {
                /** @var PurchaseOrderItem|null $item */
                $item = $order->items->firstWhere('id', $itemId);
                if (! $item) {
                    throw ValidationException::withMessages(['items' => 'That line is not on this purchase order.']);
                }
                $remaining = $item->quantity - $item->quantity_received;
                if ($quantity > $remaining) {
                    throw ValidationException::withMessages(["items.{$itemId}" => "Only {$remaining} more of {$item->sku} are expected."]);
                }

                $landed = $order->landedUnitCost($item);
                self::addToStock(Product::lockForUpdate()->find($item->product_id), $quantity, $landed, $order->number);
                $item->increment('quantity_received', $quantity);
                $lines[] = ['purchase_order_item_id' => $item->id, 'quantity' => $quantity, 'unit_cost' => round($landed, 2), 'exact_cost' => $landed];
            }

            // Value from the exact landed costs, rounded once. When this delivery completes the order, take the
            // remainder, so the deliveries add up to exactly the order total (no cent lost to rounding).
            $value = round(array_sum(array_map(fn ($l) => $l['quantity'] * $l['exact_cost'], $lines)), 2);
            $order->load('items');
            if ($order->items->every(fn ($i) => $i->quantity_received >= $i->quantity)) {
                $value = round($order->total - $order->receipts()->sum('value'), 2);
            }
            $lines = array_map(fn ($l) => array_diff_key($l, ['exact_cost' => 1]), $lines);
            $receipt = $order->receipts()->create(['received_on' => $date, 'value' => $value, 'note' => $note]);
            $receipt->lines()->createMany($lines);

            if ($value > 0) {
                $user = $order->user;
                Ledger::setupChartOfAccounts($user);
                $entry = Ledger::post($user, $date, "Stock received · {$order->number} · {$order->supplier->name}", [
                    ['ledger_account_id' => Ledger::account($user, '1200')->id, 'debit' => $value],
                    ['ledger_account_id' => Ledger::account($user, '2000')->id, 'credit' => $value],
                ], "RCV-{$order->number}-{$order->receipts()->count()}");
                $receipt->update(['journal_entry_id' => $entry->id]);
            }

            self::refreshStatus($order);

            return $order->fresh();
        });
    }

    public static function addPayment(PurchaseOrder $order, float $amount, ?string $paidOn = null, ?string $method = null, ?string $reference = null): SupplierPayment
    {
        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages(['amount' => 'This purchase order is cancelled.']);
        }
        $amount = round($amount, 2);
        if ($amount > $order->balance_due + 0.005) {
            throw ValidationException::withMessages(['amount' => 'At most '.number_format($order->balance_due, 2).' is still owed on this order.']);
        }

        return DB::transaction(function () use ($order, $amount, $paidOn, $method, $reference) {
            $date = Carbon::parse($paidOn ?? now()->toDateString())->toDateString();
            $user = $order->user;
            Ledger::setupChartOfAccounts($user);
            $entry = Ledger::post($user, $date, "Supplier payment · {$order->number} · {$order->supplier->name}".($reference ? " · {$reference}" : ''), [
                ['ledger_account_id' => Ledger::account($user, '2000')->id, 'debit' => $amount],
                ['ledger_account_id' => Ledger::account($user, '1000')->id, 'credit' => $amount],
            ], "PAY-{$order->number}-".($order->payments()->count() + 1));

            return $order->payments()->create(compact('amount', 'method', 'reference') + ['paid_on' => $date, 'journal_entry_id' => $entry->id]);
        });
    }

    public static function deletePayment(SupplierPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment->journalEntry?->delete();
            $payment->delete();
        });
    }

    public static function cancel(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->units_received > 0) {
            throw ValidationException::withMessages(['status' => 'Stock has already arrived on this order, so it can’t be cancelled.']);
        }
        if ($order->payments()->exists()) {
            throw ValidationException::withMessages(['status' => 'Remove its payments first (or keep the order open until the supplier refunds you).']);
        }
        $order->update(['status' => 'cancelled']);

        return $order;
    }

    public static function delete(PurchaseOrder $order): void
    {
        if ($order->status !== 'draft' || $order->receipts()->exists() || $order->payments()->exists()) {
            throw ValidationException::withMessages(['status' => 'Only a draft with no deliveries or payments can be deleted. Cancel it instead.']);
        }
        $order->delete();
    }

    public static function nextNumber(User $user): string
    {
        $last = $user->purchaseOrders()->where('number', 'like', 'PO-%')->pluck('number')
            ->map(fn ($n) => (int) substr($n, 3))->max();

        return 'PO-'.(($last ?: 1000) + 1);
    }

    /** Units on order that haven't arrived yet, per product id. */
    public static function incoming(User $user): array
    {
        return PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.user_id', $user->id)
            ->whereIn('purchase_orders.status', PurchaseOrder::OPEN_STATUSES)
            ->groupBy('purchase_order_items.product_id')
            ->selectRaw('purchase_order_items.product_id, SUM(purchase_order_items.quantity - purchase_order_items.quantity_received) as incoming')
            ->pluck('incoming', 'product_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private static function header(array $data): array
    {
        return array_intersect_key($data, array_flip(['expected_on', 'warehouse', 'shipping_cost', 'other_costs', 'notes']));
    }

    private static function syncItems(User $user, PurchaseOrder $order, array $items): void
    {
        if (! $items) {
            throw ValidationException::withMessages(['items' => 'Add at least one product.']);
        }
        $products = $user->products()->whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
        $existing = $order->items()->get()->keyBy('product_id');
        $keep = [];

        foreach ($items as $i => $line) {
            $product = $products[$line['product_id']] ?? throw ValidationException::withMessages(["items.{$i}.product_id" => 'Unknown product.']);
            $item = $existing[$product->id] ?? null;
            if ($item && $line['quantity'] < $item->quantity_received) {
                throw ValidationException::withMessages(["items.{$i}.quantity" => "{$item->quantity_received} of {$product->sku} have already arrived."]);
            }
            $attributes = ['sku' => $product->sku, 'name' => $product->name, 'quantity' => (int) $line['quantity'], 'unit_cost' => round((float) $line['unit_cost'], 2)];
            $item ? $item->update($attributes) : $order->items()->create($attributes + ['product_id' => $product->id]);
            $keep[] = $product->id;
        }

        $removed = $existing->except($keep);
        if ($removed->contains(fn ($item) => $item->quantity_received > 0)) {
            throw ValidationException::withMessages(['items' => 'A line that has already been received can’t be removed.']);
        }
        $order->items()->whereIn('id', $removed->pluck('id'))->delete();
    }

    /** Add received units to stock at the weighted average of the current cost and the landed cost. */
    private static function addToStock(Product $product, int $quantity, float $landedCost, string $reference): void
    {
        $onHand = max(0, $product->on_hand);
        $product->unit_cost = round(($onHand * $product->unit_cost + $quantity * $landedCost) / ($onHand + $quantity), 2);
        $product->adjustStock($quantity, 'Purchase order receipt', $reference);
    }

    private static function refreshStatus(PurchaseOrder $order): void
    {
        if (in_array($order->status, ['draft', 'cancelled'], true)) {
            return;
        }
        $order->load('items');
        $received = $order->items->sum('quantity_received');
        $ordered = $order->items->sum('quantity');
        $order->update(['status' => $received <= 0 ? 'ordered' : ($received >= $ordered ? 'received' : 'partially_received')]);
    }
}
