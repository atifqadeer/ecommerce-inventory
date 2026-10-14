<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    public const WAREHOUSES = ['East Coast', 'West Coast'];

    protected $fillable = ['sku', 'name', 'warehouse', 'price', 'unit_cost', 'on_hand', 'reserved', 'reorder_point'];

    protected $appends = ['available', 'is_low_stock'];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'unit_cost' => 'float',
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'reorder_point' => 'integer',
        ];
    }

    public function getAvailableAttribute(): int
    {
        return max(0, $this->on_hand - $this->reserved);
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->available < $this->reorder_point;
    }

    /** Default warehouses plus any a workspace already uses (e.g. "Shopify" for imported products). */
    public static function warehousesFor(User $user): array
    {
        return collect(self::WAREHOUSES)->merge($user->products()->distinct()->pluck('warehouse'))->unique()->values()->all();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(ChannelListing::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Change on-hand stock and record why. */
    public function adjustStock(int $quantity, string $reason, ?string $note = null): StockMovement
    {
        $this->on_hand = max(0, $this->on_hand + $quantity);
        $this->save();

        return $this->movements()->create(['quantity' => $quantity, 'reason' => $reason, 'note' => $note]);
    }
}
