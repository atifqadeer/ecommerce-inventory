<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->text('credentials')->nullable()->after('handle'); // encrypted API credentials
            $table->string('currency', 3)->nullable()->after('color');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('channel_id');
            $table->index(['channel_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['channel_id', 'external_id']);
            $table->dropColumn('external_id');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['credentials', 'currency']);
        });
    }
};
