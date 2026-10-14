<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A money movement on a store's payment account, e.g. a Shopify Payments charge, refund or Shop campaign cost.
 * Order-related types roll up into the order's actual fees and refunds; the rest become expenses.
 */
class PaymentTransaction extends Model
{
    /**
     * type => [label, sign, effect]
     *   sign:   "+" always a credit, "-" always a debit, "±" as entered
     *   effect: sale | tax | refund | chargeback | expense:<category> | none
     */
    public const TYPES = [
        'charge' => ['Charge (customer payment)', '+', 'sale'],
        'shop_cash_credit' => ['Shop Cash credit', '+', 'sale'],
        'shopify_collective_credit' => ['Shopify Collective credit', '+', 'sale'],
        'marketplace_sales_tax' => ['Marketplace sales tax', '±', 'tax'],
        'refund' => ['Refund', '-', 'refund'],
        'shop_cash_refund_debit' => ['Shop Cash refund', '-', 'refund'],
        'chargeback' => ['Chargeback', '-', 'chargeback'],
        'chargeback_won' => ['Chargeback won', '+', 'chargeback'],
        'shop_cash_campaign_billing_debit' => ['Shop campaign cost', '-', 'expense:advertising'],
        'adjustment' => ['Adjustment', '±', 'expense:fees'],
        'other' => ['Other', '±', 'none'],
    ];

    protected $fillable = [
        'user_id', 'channel_id', 'order_id', 'expense_id', 'statement_import_id', 'source', 'entered_manually', 'type', 'order_ref',
        'occurred_at', 'amount', 'fee', 'net', 'currency', 'payout_date', 'payout_ref', 'payout_status', 'note', 'match_key', 'occurrence',
    ];

    protected $hidden = ['match_key'];

    protected $appends = ['type_label'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payout_date' => 'date:Y-m-d',
            'amount' => 'float',
            'fee' => 'float',
            'net' => 'float',
            'entered_manually' => 'boolean',
        ];
    }

    public static function effect(string $type): string
    {
        return self::TYPES[$type][2] ?? 'none';
    }

    /** Apply the type's natural sign to an amount typed by hand (refunds are debits even if typed as 50). */
    public static function signed(string $type, float $amount): float
    {
        return match (self::TYPES[$type][1] ?? '±') {
            '+' => abs($amount),
            '-' => -abs($amount),
            default => $amount,
        };
    }

    /** What identifies "the same transaction" whether it was typed in or imported: store, type, order, amount and day. */
    public static function matchKey(string $type, ?string $orderRef, float $amount, string $date): string
    {
        return md5(implode('|', [$type, ltrim((string) $orderRef, '#'), number_format($amount, 2, '.', ''), $date]));
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type][0] ?? (string) $this->type;
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function statementImport(): BelongsTo
    {
        return $this->belongsTo(StatementImport::class);
    }
}
