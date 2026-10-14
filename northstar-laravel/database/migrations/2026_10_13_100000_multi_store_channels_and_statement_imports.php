<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A channel is now one store on a platform: "name" is the store's label, "platform" is Shopify/Amazon/…
        Schema::table('channels', function (Blueprint $table) {
            $table->string('platform')->default('')->after('user_id');
        });
        DB::table('channels')->update(['platform' => DB::raw('name')]);

        Schema::create('statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('format');
            $table->string('filename');
            $table->string('path')->nullable();
            $table->string('hash', 40);
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedInteger('orders_created')->default(0);
            $table->unsignedInteger('orders_updated')->default(0);
            $table->unsignedInteger('expenses')->default(0);
            $table->unsignedInteger('products_created')->default(0);
            $table->integer('units')->default(0);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'hash']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Order numbers only need to be unique per store (three Shopify stores can each have #1001).
            $table->index('user_id');
            $table->dropUnique(['user_id', 'number']);
            $table->unique(['user_id', 'channel_id', 'number']);
            $table->foreignId('statement_import_id')->nullable()->after('channel_id')->constrained()->nullOnDelete();
            // True when this order's units have already been taken off on-hand stock.
            $table->boolean('stock_deducted')->default(false)->after('fulfillment');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('statement_import_id')->nullable()->after('channel_id')->constrained()->nullOnDelete();
            $table->string('external_ref', 64)->nullable()->after('statement_import_id');
            $table->index(['channel_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['channel_id', 'external_ref']);
            $table->dropConstrainedForeignId('statement_import_id');
            $table->dropColumn('external_ref');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('statement_import_id');
            $table->dropColumn('stock_deducted');
            $table->dropUnique(['user_id', 'channel_id', 'number']);
            $table->unique(['user_id', 'number']);
            $table->dropIndex(['user_id']);
        });

        Schema::dropIfExists('statement_imports');

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
