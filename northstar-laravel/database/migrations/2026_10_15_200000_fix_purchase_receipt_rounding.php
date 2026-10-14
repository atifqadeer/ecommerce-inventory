<?php

use App\Models\PurchaseOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Deliveries were valued at landed unit costs rounded to the cent, so a fully received order could book a cent
     * or two less than its total (3 × 18.33 = 54.99 for a 55.00 order). Make the last delivery of each fully received
     * order take the remainder, and correct its Inventory / Supplier payables journal entry to match.
     */
    public function up(): void
    {
        PurchaseOrder::with(['items', 'receipts'])->where('status', 'received')->each(function (PurchaseOrder $order) {
            $last = $order->receipts->sortBy('id')->last();
            $difference = round($order->total - $order->receipts->sum('value'), 2);
            if (! $last || $difference == 0.0) {
                return;
            }

            $value = round($last->value + $difference, 2);
            DB::table('purchase_receipts')->where('id', $last->id)->update(['value' => $value]);
            if ($last->journal_entry_id) {
                DB::table('journal_lines')->where('journal_entry_id', $last->journal_entry_id)->where('debit', '>', 0)->update(['debit' => $value]);
                DB::table('journal_lines')->where('journal_entry_id', $last->journal_entry_id)->where('credit', '>', 0)->update(['credit' => $value]);
            }
        });
    }

    public function down(): void
    {
        // Rounding repair only.
    }
};
