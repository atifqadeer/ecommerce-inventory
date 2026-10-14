<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = ['product_id', 'sku', 'name', 'quantity', 'unit_price', 'unit_cost'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'float', 'unit_cost' => 'float'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
