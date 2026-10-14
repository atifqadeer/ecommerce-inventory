<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * On MySQL/MariaDB with explicit_defaults_for_timestamp=OFF, the first NOT NULL TIMESTAMP column of a table
     * silently gets "ON UPDATE CURRENT_TIMESTAMP". orders.placed_at was that column, so any update that didn't set
     * placed_at (a status change, a refund, fees from an import) moved the order's date to "now".
     *
     * Drop the auto-update, then restore each order's real date: the earliest payment transaction for orders known
     * only from payments, otherwise the "Order placed" event recorded when the order was created.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE orders MODIFY placed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        }

        $paymentsOnly = DB::table('payment_transactions')
            ->join('orders', 'orders.id', '=', 'payment_transactions.order_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('order_items')->whereColumn('order_items.order_id', 'orders.id'))
            ->groupBy('payment_transactions.order_id')
            ->selectRaw('payment_transactions.order_id as order_id, MIN(payment_transactions.occurred_at) as placed_at')
            ->pluck('placed_at', 'order_id');

        $placedEvents = DB::table('order_events')->where('label', 'Order placed')
            ->groupBy('order_id')->selectRaw('order_id, MIN(created_at) as placed_at')->pluck('placed_at', 'order_id');

        DB::table('orders')->select(['id', 'placed_at'])->orderBy('id')->chunkById(500, function ($orders) use ($paymentsOnly, $placedEvents) {
            foreach ($orders as $order) {
                $date = $paymentsOnly[$order->id] ?? $placedEvents[$order->id] ?? null;
                if ($date && (string) $date !== (string) $order->placed_at) {
                    DB::table('orders')->where('id', $order->id)->update(['placed_at' => $date]);
                }
            }
        });
    }

    public function down(): void
    {
        // The auto-update was never intended, so there is nothing to restore.
    }
};
