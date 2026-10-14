<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUSES = ['paid', 'processing', 'shipped', 'delivered', 'cancelled'];

    /** Statuses still holding reserved stock. */
    public const OPEN_STATUSES = ['paid', 'processing'];

    /** Allowed next statuses for each status. */
    public const TRANSITIONS = [
        'paid' => ['processing', 'shipped', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'channel_id', 'statement_import_id', 'external_id', 'number', 'customer_name', 'customer_email', 'shipping_address', 'status', 'fulfillment',
        'stock_deducted', 'fees_actual', 'subtotal', 'channel_fees', 'shipping_cost', 'cogs', 'refunds', 'placed_at',
    ];

    protected $appends = ['net_profit'];

    protected function casts(): array
    {
        return [
            'placed_at' => 'datetime',
            'stock_deducted' => 'boolean',
            'fees_actual' => 'boolean',
            'subtotal' => 'float',
            'channel_fees' => 'float',
            'shipping_cost' => 'float',
            'cogs' => 'float',
            'refunds' => 'float',
        ];
    }

    public function getNetProfitAttribute(): float
    {
        return round($this->subtotal - $this->channel_fees - $this->shipping_cost - $this->cogs - $this->refunds, 2);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }
}
