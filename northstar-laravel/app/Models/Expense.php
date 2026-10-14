<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Expense extends Model
{
    public const CATEGORIES = [
        'payment_processing' => 'Payment processing',
        'advertising' => 'Advertising',
        'software' => 'Software & admin',
        // Mostly recorded from marketplace statements (subscriptions, storage and fulfilment fees).
        'fees' => 'Marketplace fees',
        'shipping' => 'Shipping & fulfilment',
    ];

    protected $fillable = ['channel_id', 'statement_import_id', 'external_ref', 'category', 'description', 'amount', 'incurred_on'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'incurred_on' => 'date:Y-m-d'];
    }

    /** The payment transaction this cost came from (e.g. a Shop campaign charge), if any. */
    public function paymentTransaction(): HasOne
    {
        return $this->hasOne(PaymentTransaction::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
