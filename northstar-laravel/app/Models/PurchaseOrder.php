<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock bought from a supplier. Shipping and other costs are spread over the lines by value, so each
 * unit's landed cost is what it really cost to get it into the warehouse.
 */
class PurchaseOrder extends Model
{
    public const STATUSES = ['draft', 'ordered', 'partially_received', 'received', 'cancelled'];

    /** Statuses whose unreceived units are on their way. */
    public const OPEN_STATUSES = ['ordered', 'partially_received'];

    protected $fillable = ['supplier_id', 'number', 'status', 'ordered_on', 'expected_on', 'warehouse', 'shipping_cost', 'other_costs', 'notes'];

    protected $appends = ['subtotal', 'total', 'amount_paid', 'balance_due', 'payment_status', 'units_ordered', 'units_received'];

    protected function casts(): array
    {
        return [
            'ordered_on' => 'date:Y-m-d',
            'expected_on' => 'date:Y-m-d',
            'shipping_cost' => 'float',
            'other_costs' => 'float',
        ];
    }

    public function getSubtotalAttribute(): float
    {
        return round($this->items->sum(fn ($i) => $i->quantity * $i->unit_cost), 2);
    }

    public function getTotalAttribute(): float
    {
        return round($this->subtotal + $this->shipping_cost + $this->other_costs, 2);
    }

    public function getAmountPaidAttribute(): float
    {
        return round($this->payments->sum('amount'), 2);
    }

    public function getBalanceDueAttribute(): float
    {
        return $this->status === 'cancelled' ? 0.0 : round($this->total - $this->amount_paid, 2);
    }

    public function getPaymentStatusAttribute(): string
    {
        return match (true) {
            $this->amount_paid <= 0 => 'unpaid',
            $this->amount_paid + 0.005 < $this->total => 'partially_paid',
            default => 'paid',
        };
    }

    public function getUnitsOrderedAttribute(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function getUnitsReceivedAttribute(): int
    {
        return (int) $this->items->sum('quantity_received');
    }

    /** Landed cost of one unit of a line: its price plus its share (by value) of shipping and other costs. */
    public function landedUnitCost(PurchaseOrderItem $item): float
    {
        $extra = $this->shipping_cost + $this->other_costs;
        $subtotal = $this->subtotal;
        if ($extra == 0.0 || $subtotal <= 0 || $item->quantity === 0) {
            return round($item->unit_cost, 4);
        }

        return round($item->unit_cost + $extra * ($item->quantity * $item->unit_cost / $subtotal) / $item->quantity, 4);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
