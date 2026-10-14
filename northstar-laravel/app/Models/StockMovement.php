<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const REASONS = ['Cycle count correction', 'Damaged stock', 'Receiving', 'Manual correction'];

    protected $fillable = ['quantity', 'reason', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
