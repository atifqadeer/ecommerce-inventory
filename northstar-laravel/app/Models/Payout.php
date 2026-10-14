<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A transfer from a store's payment account to the bank. Paid payouts are posted to the ledger as cash received. */
class Payout extends Model
{
    public const STATUSES = ['scheduled', 'in_transit', 'paid', 'failed', 'canceled'];

    protected $fillable = [
        'user_id', 'channel_id', 'statement_import_id', 'journal_entry_id', 'source', 'entered_manually', 'paid_on', 'status',
        'charges', 'refunds', 'adjustments', 'tax', 'fees', 'total', 'currency', 'bank_reference', 'match_key', 'occurrence',
    ];

    protected $hidden = ['match_key'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date:Y-m-d',
            'entered_manually' => 'boolean',
            'charges' => 'float',
            'refunds' => 'float',
            'adjustments' => 'float',
            'tax' => 'float',
            'fees' => 'float',
            'total' => 'float',
        ];
    }

    /** Same store, date and amount means the same payout. */
    public static function matchKey(string $date, float $total): string
    {
        return md5($date.'|'.number_format($total, 2, '.', ''));
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function statementImport(): BelongsTo
    {
        return $this->belongsTo(StatementImport::class);
    }
}
