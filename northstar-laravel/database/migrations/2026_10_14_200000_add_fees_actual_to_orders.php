<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // True once sales, fees and refunds come from a payments statement rather than an estimate,
            // so a later orders-only report can't overwrite them.
            $table->boolean('fees_actual')->default(false)->after('stock_deducted');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('fees_actual');
        });
    }
};
