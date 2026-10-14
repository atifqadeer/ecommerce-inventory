<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    public const JOB_TYPES = ['Orders sync', 'Inventory sync', 'Listings sync'];

    protected $fillable = ['job_type', 'records', 'status', 'error', 'created_at'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
