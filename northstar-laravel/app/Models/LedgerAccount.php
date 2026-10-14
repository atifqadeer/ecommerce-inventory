<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    public const TYPES = ['asset', 'liability', 'equity'];

    protected $fillable = ['parent_id', 'type', 'code', 'name', 'detail', 'sort'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** Assets carry a debit balance; liabilities and equity carry a credit balance. */
    public function isDebitNormal(): bool
    {
        return $this->type === 'asset';
    }
}
