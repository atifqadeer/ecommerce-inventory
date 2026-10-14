<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('handle');
            $table->string('color', 16)->default('#6366f1');
            $table->string('status')->default('connected'); // connected | action_needed | disconnected
            $table->timestamp('token_expires_at')->nullable();
            $table->string('sync_frequency')->default('Hourly');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->string('name');
            $table->string('warehouse')->default('East Coast');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->unsignedInteger('on_hand')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('reorder_point')->default(20);
            $table->timestamps();
            $table->unique(['user_id', 'sku']);
        });

        Schema::create('channel_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->unsignedInteger('allocated')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'channel_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->string('reason');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number');
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('status')->default('paid'); // paid | processing | shipped | delivered | cancelled
            $table->string('fulfillment', 8)->default('FBM'); // FBA | FBM
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('channel_fees', 12, 2)->default(0);
            $table->decimal('shipping_cost', 12, 2)->default(0);
            $table->decimal('cogs', 12, 2)->default(0);
            $table->decimal('refunds', 12, 2)->default(0);
            $table->timestamp('placed_at');
            $table->timestamps();
            $table->unique(['user_id', 'number']);
            $table->index(['user_id', 'placed_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku');
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('job_type');
            $table->unsignedInteger('records')->nullable();
            $table->string('status'); // success | failed
            $table->string('error')->nullable();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category'); // payment_processing | advertising | software
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('incurred_on');
            $table->timestamps();
        });

        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('ledger_accounts')->cascadeOnDelete();
            $table->string('type'); // asset | liability | equity
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('detail')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference');
            $table->date('date');
            $table->string('description');
            $table->timestamps();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->cascadeOnDelete();
            $table->decimal('debit', 12, 2)->default(0);
            $table->decimal('credit', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        foreach (['journal_lines', 'journal_entries', 'ledger_accounts', 'expenses', 'sync_logs', 'order_events', 'order_items', 'orders', 'stock_movements', 'channel_listings', 'products', 'channels'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
