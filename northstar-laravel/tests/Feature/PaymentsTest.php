<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Shopify payment transactions and payouts, entered by hand or imported, never stored twice. */
class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const TX_HEADER = "Transaction Date,Type,Order,Card Brand,Card Source,Payout Status,Payout Date,Payout ID,Available On,Amount,Fee,Net,Checkout,Payment Method Name,Presentment Amount,Presentment Currency,Currency\n";

    private const PAYOUT_HEADER = "Payout Date,Status,Charges,Refunds,Adjustments,Marketplace Sales Tax,Advances,Reserved Funds,Fees,Retried Amount,Total,Currency,Bank Reference\n";

    private User $user;

    private Channel $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
        $id = $this->postJson('/api/channels', ['platform' => 'Shopify', 'name' => 'WTN Essentials', 'connection' => 'statements'])->json('channel.id');
        $this->store = Channel::find($id);
    }

    private function upload(string $name, string $content)
    {
        return $this->post("/api/channels/{$this->store->id}/statements", ['file' => UploadedFile::fake()->createWithContent($name, $content)], ['Accept' => 'application/json']);
    }

    private function transactionsCsv(): string
    {
        return self::TX_HEADER
            ."2026-07-16 22:28:23 -0400,marketplace sales tax,2542614,unknown,online,pending,2026-07-17,,2026-07-17,-0.79,0.00,-0.79,,,,,USD\n"
            ."2026-07-16 22:28:22 -0400,charge,2542614,unknown,online,scheduled,2026-07-19,133916000448,2026-07-17,12.11,0.91,11.20,#35895253467328,paypal,12.11,USD,USD\n"
            ."2026-07-16 10:49:56 -0400,refund,2542595,visa,online,pending,2026-07-20,,2026-07-20,-46.20,0.00,-46.20,#35881986162880,card,46.20,USD,USD\n"
            ."2026-07-15 07:23:01 -0400,shop_cash_credit,2542531,unknown,online,paid,2026-07-16,133851480256,2026-07-15,10.00,0.29,9.71,,,,,USD\n"
            ."2026-07-10 00:37:38 -0400,chargeback,2542390,visa,online,paid,2026-07-13,133714215104,2026-07-10,-28.23,15.00,-43.23,#35697130471616,card,28.23,USD,USD\n"
            ."2026-07-06 18:13:25 -0400,adjustment,,unknown,online,paid,2026-07-07,133502468288,2026-07-06,-67.97,0.00,-67.97,,,,,USD\n"
            ."2026-02-02 01:58:35 -0500,shop_cash_campaign_billing_debit,2541813,unknown,online,paid,2026-02-04,128096764096,2026-02-02,-14.31,0.00,-14.31,,,,,USD\n";
    }

    public function test_transactions_import_sets_actual_fees_refunds_and_costs(): void
    {
        $import = $this->upload('wtn-payment_transactions.csv', $this->transactionsCsv())->assertCreated()->json();
        $this->assertSame(['shopify_payment_transactions', 7, 0], [$import['format'], $import['transactions'], $import['matched']]);

        // Known only from payments: sales are the charge less the sales tax Shopify keeps back.
        $order = $this->store->orders()->where('number', '#2542614')->sole();
        $this->assertSame([11.32, 0.91, 0.0], [$order->subtotal, $order->channel_fees, $order->refunds]);
        $this->assertSame([46.2, 0.0], [$this->store->orders()->where('number', '#2542595')->value('refunds'), $this->store->orders()->where('number', '#2542595')->value('subtotal')]);
        $chargeback = $this->store->orders()->where('number', '#2542390')->sole();
        $this->assertSame([28.23, 15.0], [$chargeback->refunds, $chargeback->channel_fees]);

        $expenses = $this->user->expenses()->orderBy('amount')->get()->map(fn ($e) => [$e->category, $e->amount])->all();
        $this->assertSame([['advertising', 14.31], ['fees', 67.97]], $expenses);
    }

    public function test_overlapping_exports_and_identical_rows_are_not_duplicated(): void
    {
        $this->upload('june.csv', $this->transactionsCsv())->assertCreated();
        // A later export repeats every row and adds one more; two identical rows in one file stay two transactions.
        $later = $this->transactionsCsv()
            ."2026-07-15 07:23:01 -0400,shop_cash_credit,2542576,unknown,online,paid,2026-07-16,133851480256,2026-07-15,10.00,0.29,9.71,,,,,USD\n"
            ."2026-07-15 07:23:01 -0400,shop_cash_credit,2542576,unknown,online,paid,2026-07-16,133851480256,2026-07-15,10.00,0.29,9.71,,,,,USD\n";

        $import = $this->upload('july.csv', $later)->assertCreated()->json();
        $this->assertSame([9, 7], [$import['transactions'], $import['matched']]);
        $this->assertSame(9, $this->user->paymentTransactions()->count());
        $this->assertSame(2, $this->user->expenses()->count());
        $this->assertSame(20.0, $this->store->orders()->where('number', '#2542576')->value('subtotal'));

        // Undoing the later upload removes only the two rows it added.
        $this->deleteJson("/api/statement-imports/{$import['id']}")->assertOk();
        $this->assertSame(7, $this->user->paymentTransactions()->count());
        $this->assertSame(0, $this->store->orders()->where('number', '#2542576')->count());
    }

    public function test_manual_entry_is_taken_over_by_a_later_import(): void
    {
        $manual = $this->postJson('/api/payments/transactions', [
            'channel_id' => $this->store->id, 'type' => 'refund', 'order_ref' => '#2542595', 'occurred_at' => '2026-07-16', 'amount' => 46.20,
        ])->assertCreated()->json();
        $this->assertSame([-46.2, 'manual'], [$manual['amount'], $manual['source']]);

        $import = $this->upload('tx.csv', $this->transactionsCsv())->assertCreated()->json();
        $this->assertSame(1, $import['matched']);
        $this->assertSame(7, $this->user->paymentTransactions()->count());
        $refund = $this->user->paymentTransactions()->find($manual['id']);
        $this->assertSame(['import', true, '2026-07-16 10:49:56'], [$refund->source, $refund->entered_manually, $refund->occurred_at->format('Y-m-d H:i:s')]);

        // Removing the upload keeps the hand-entered transaction and deletes only what the file added.
        $this->deleteJson("/api/statement-imports/{$import['id']}")->assertOk();
        $this->assertSame(['manual'], $this->user->paymentTransactions()->pluck('source')->all());
        $this->assertSame(1, $this->user->orders()->count());
    }

    public function test_manual_entry_matching_an_imported_one_is_refused_unless_confirmed(): void
    {
        $this->upload('tx.csv', $this->transactionsCsv())->assertCreated();
        $entry = ['channel_id' => $this->store->id, 'type' => 'charge', 'order_ref' => '2542614', 'occurred_at' => '2026-07-16', 'amount' => 12.11, 'fee' => 0.91];

        $this->postJson('/api/payments/transactions', $entry)->assertUnprocessable()->assertJsonValidationErrors('duplicate');
        $this->postJson('/api/payments/transactions', $entry + ['allow_duplicate' => true])->assertCreated();
        $this->assertSame(2, $this->user->paymentTransactions()->where('order_ref', '2542614')->where('type', 'charge')->count());

        // Re-importing the file leaves the confirmed second charge alone.
        $this->upload('tx-again.csv', $this->transactionsCsv()."\n")->assertCreated();
        $this->assertSame(2, $this->user->paymentTransactions()->where('order_ref', '2542614')->where('type', 'charge')->count());
    }

    public function test_payouts_import_merges_manual_entries_and_posts_paid_ones_to_cash(): void
    {
        $this->postJson('/api/payments/payouts', ['channel_id' => $this->store->id, 'paid_on' => '2026-06-30', 'status' => 'paid', 'total' => 451.70])->assertCreated();
        $this->postJson('/api/payments/payouts', ['channel_id' => $this->store->id, 'paid_on' => '2026-06-30', 'status' => 'paid', 'total' => 451.70])
            ->assertUnprocessable()->assertJsonValidationErrors('duplicate');

        $csv = self::PAYOUT_HEADER
            ."2026-07-19,scheduled,12.11,0.00,0.00,0.00,0.00,0.00,0.91,0.00,11.20,USD,\n"
            ."2026-07-02,paid,0.00,-21.22,0.00,0.00,0.00,0.00,0.00,0.00,-21.22,USD,1051397310274\n"
            ."2026-06-30,paid,452.09,0.00,35.49,-13.28,0.00,0.00,22.60,0.00,451.70,USD,\n";
        $import = $this->upload('wtn-payout.csv', $csv)->assertCreated()->json();
        $this->assertSame(['shopify_payouts', 3, 1], [$import['format'], $import['payouts'], $import['matched']]);
        $this->assertSame(3, $this->user->payouts()->count());
        $this->assertSame(22.6, $this->user->payouts()->where('paid_on', '2026-06-30')->value('fees'));

        // Paid payouts only: +451.70 and -21.22 reach cash; the scheduled one waits.
        $this->assertSame(2, $this->user->journalEntries()->count());
        $this->getJson('/api/finance/balance-sheet')->assertOk()->assertJsonPath('balanced', true);

        // When the scheduled payout is paid (in a newer export, or by hand), it is posted once.
        $this->upload('wtn-payout-2.csv', str_replace('2026-07-19,scheduled', '2026-07-19,paid', $csv))->assertCreated();
        $this->assertSame(3, $this->user->journalEntries()->count());
        $this->assertSame(3, $this->user->payouts()->count());
    }

    public function test_orders_export_and_transactions_combine_in_either_order(): void
    {
        $this->post('/api/products/import', ['file' => UploadedFile::fake()->createWithContent('stock.csv', "sku,on_hand,unit_cost\nMUG,20,4\n")], ['Accept' => 'application/json']);
        $orders = "Name,Financial Status,Fulfillment Status,Subtotal,Created at,Lineitem quantity,Lineitem name,Lineitem price,Lineitem sku\n"
            ."#2542614,paid,fulfilled,12.11,2026-07-16 22:28:00 -0400,1,Mug,12.11,MUG\n";

        $this->upload('tx.csv', $this->transactionsCsv())->assertCreated();
        $this->upload('orders_export.csv', $orders)->assertCreated()->assertJsonPath('orders_updated', 1);

        $order = $this->store->orders()->where('number', '#2542614')->with('items')->sole();
        // Line items and subtotal from the orders export; the actual fee from the transactions (not the 6% estimate).
        $this->assertSame([12.11, 0.91, 1, 4.0], [$order->subtotal, $order->channel_fees, $order->items->count(), $order->cogs]);
        $this->assertSame(19, $this->user->products()->firstWhere('sku', 'MUG')->on_hand);
        $this->assertSame(1, $this->store->orders()->where('number', '#2542614')->count());
    }

    public function test_manual_order_is_matched_by_a_later_orders_export(): void
    {
        $this->post('/api/products/import', ['file' => UploadedFile::fake()->createWithContent('stock.csv', "sku,on_hand\nMUG,20\n")], ['Accept' => 'application/json']);
        $mug = $this->user->products()->sole();

        $this->postJson('/api/orders', ['channel_id' => $this->store->id, 'number' => '2542700', 'customer_name' => 'Phone order', 'fulfillment' => 'FBM',
            'items' => [['product_id' => $mug->id, 'quantity' => 2]]])->assertCreated()->assertJsonPath('number', '#2542700');
        $this->assertSame(2, $mug->fresh()->reserved);

        $this->postJson('/api/orders', ['channel_id' => $this->store->id, 'number' => '#2542700', 'customer_name' => 'Again', 'fulfillment' => 'FBM',
            'items' => [['product_id' => $mug->id, 'quantity' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('number');

        $orders = "Name,Financial Status,Fulfillment Status,Created at,Lineitem quantity,Lineitem name,Lineitem price,Lineitem sku\n#2542700,paid,fulfilled,2026-07-18 10:00:00 -0400,2,Mug,12,MUG\n";
        $this->upload('orders_export.csv', $orders)->assertCreated()->assertJsonPath('orders_updated', 1);

        $this->assertSame(1, $this->store->orders()->count());
        // The reservation is released and the shipped units are taken off stock once.
        $this->assertSame([18, 0], [$mug->fresh()->on_hand, $mug->fresh()->reserved]);
    }

    public function test_expenses_typed_in_are_not_duplicated_by_imports(): void
    {
        $this->postJson('/api/expenses', ['category' => 'advertising', 'channel_id' => $this->store->id, 'amount' => 14.31, 'incurred_on' => '2026-02-02', 'description' => 'Shop campaign'])->assertCreated();
        $this->postJson('/api/expenses', ['category' => 'advertising', 'channel_id' => $this->store->id, 'amount' => 14.31, 'incurred_on' => '2026-02-02'])
            ->assertUnprocessable()->assertJsonValidationErrors('duplicate');

        $this->upload('tx.csv', $this->transactionsCsv())->assertCreated();
        $this->assertSame(1, $this->user->expenses()->where('category', 'advertising')->count());

        $expense = $this->user->expenses()->where('category', 'advertising')->sole();
        $this->deleteJson("/api/expenses/{$expense->id}")->assertUnprocessable();
    }
}
