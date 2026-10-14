<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money movements on a store's payment account (Shopify Payments transactions), entered by hand or imported.
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('statement_import_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16)->default('manual'); // manual | import
            $table->boolean('entered_manually')->default(false); // kept when an import takes over a manual entry
            $table->string('type');
            $table->string('order_ref')->nullable();
            $table->dateTime('occurred_at');
            $table->decimal('amount', 12, 2);
            $table->decimal('fee', 12, 2)->default(0);
            $table->decimal('net', 12, 2);
            $table->string('currency', 3)->nullable();
            $table->date('payout_date')->nullable();
            $table->string('payout_ref')->nullable();
            $table->string('payout_status')->nullable();
            $table->string('note')->nullable();
            // Identity used to match the same transaction across manual entry and imports.
            $table->string('match_key', 32);
            $table->unsignedSmallInteger('occurrence')->default(1);
            $table->timestamps();
            $table->unique(['channel_id', 'match_key', 'occurrence']);
            $table->index(['channel_id', 'occurred_at']);
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('statement_import_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16)->default('manual');
            $table->boolean('entered_manually')->default(false);
            $table->date('paid_on');
            $table->string('status')->default('paid'); // scheduled | in_transit | paid | failed | canceled
            $table->decimal('charges', 12, 2)->default(0);
            $table->decimal('refunds', 12, 2)->default(0);
            $table->decimal('adjustments', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('fees', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('currency', 3)->nullable();
            $table->string('bank_reference')->nullable();
            $table->string('match_key', 32);
            $table->unsignedSmallInteger('occurrence')->default(1);
            $table->timestamps();
            $table->unique(['channel_id', 'match_key', 'occurrence']);
        });

        Schema::table('statement_imports', function (Blueprint $table) {
            $table->unsignedInteger('transactions')->default(0)->after('expenses');
            $table->unsignedInteger('payouts')->default(0)->after('transactions');
            $table->unsignedInteger('matched')->default(0)->after('payouts'); // existing records (incl. manual ones) updated instead of duplicated
        });
    }

    public function down(): void
    {
        Schema::table('statement_imports', function (Blueprint $table) {
            $table->dropColumn(['transactions', 'payouts', 'matched']);
        });
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payment_transactions');
    }
};
