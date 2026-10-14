<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatementImport extends Model
{
    protected $fillable = [
        'user_id', 'channel_id', 'format', 'filename', 'path', 'hash', 'rows', 'orders_created', 'orders_updated',
        'expenses', 'transactions', 'payouts', 'matched', 'products_created', 'units', 'period_from', 'period_to', 'warnings',
    ];

    protected $hidden = ['path', 'hash'];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'warnings' => 'array',
            'rows' => 'integer',
            'orders_created' => 'integer',
            'orders_updated' => 'integer',
            'expenses' => 'integer',
            'transactions' => 'integer',
            'payouts' => 'integer',
            'matched' => 'integer',
            'products_created' => 'integer',
            'units' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function expenseRecords(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
