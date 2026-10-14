<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = ['product_id', 'sku', 'name', 'quantity', 'quantity_received', 'unit_cost'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'quantity_received' => 'integer', 'unit_cost' => 'float'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
