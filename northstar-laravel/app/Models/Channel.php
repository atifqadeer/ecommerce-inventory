<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One store on a marketplace platform. A workspace can have several stores per platform
 * (e.g. three Shopify stores and two Amazon marketplaces); "name" is the store's own label.
 */
class Channel extends Model
{
    /** Marketplace platforms, with their brand colour. */
    public const SUPPORTED = [
        'Shopify' => '#78c091',
        'Amazon' => '#f59e0b',
        'Etsy' => '#ee6b47',
        'eBay' => '#4f8cff',
    ];

    /** Platforms with a live API integration. Every platform can be kept up to date by uploading statements. */
    public const INTEGRATED = ['Shopify'];

    public const FREQUENCIES = ['Every 15 minutes', 'Every 30 minutes', 'Hourly', 'Every 6 hours', 'Daily'];

    /** Minutes between automatic syncs for each frequency. */
    public const FREQUENCY_MINUTES = ['Every 15 minutes' => 15, 'Every 30 minutes' => 30, 'Hourly' => 60, 'Every hour' => 60, 'Every 6 hours' => 360, 'Daily' => 1440];

    protected $fillable = ['platform', 'name', 'handle', 'credentials', 'color', 'currency', 'status', 'token_expires_at', 'sync_frequency', 'last_synced_at'];

    protected $hidden = ['credentials'];

    protected $appends = ['auth_type', 'connection'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** How the channel authenticates: "access_token", "client_credentials" or null when not linked to an API. */
    public function getAuthTypeAttribute(): ?string
    {
        return $this->credentials['type'] ?? null;
    }

    /** "api" when data is pulled from the platform's API, "statements" when it comes from uploaded reports. */
    public function getConnectionAttribute(): string
    {
        return $this->auth_type ? 'api' : 'statements';
    }

    public function isDueForSync(): bool
    {
        $minutes = self::FREQUENCY_MINUTES[$this->sync_frequency] ?? 60;

        return ! $this->last_synced_at || $this->last_synced_at->addMinutes($minutes)->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(ChannelListing::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function statementImports(): HasMany
    {
        return $this->hasMany(StatementImport::class);
    }
}
