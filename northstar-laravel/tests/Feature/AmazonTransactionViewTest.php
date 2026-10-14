<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Amazon Payments → Transaction View export: actual money per order, fees and charges, no SKUs. */
class AmazonTransactionViewTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = "\"Date\",\"Transaction Status\",\"Transaction type\",\"Order ID\",\"Product Details\",\"Total product charges\",\"Total promotional rebates\",\"Amazon fees\",\"Other\",\"Total (USD)\"\n";

    private User $user;

    private Channel $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
        $this->store = Channel::find($this->postJson('/api/channels', ['platform' => 'Amazon', 'name' => 'Amazon US', 'connection' => 'statements'])->json('channel.id'));
    }

    private function upload(string $name, string $content)
    {
        return $this->post("/api/channels/{$this->store->id}/statements", ['file' => UploadedFile::fake()->createWithContent($name, $content)], ['Accept' => 'application/json']);
    }

    private function csv(): string
    {
        return self::HEADER
            ."\"9/22/2026\",\"Released\",\"Order Payment\",\"112-2483727-3749046\",\"Purest Peak Himalayan Pink Salt, Fine Gr...\",\"11.99\",\"-4.3\",\"-7.46\",\"4.3\",\"4.53\"\n"
            ."\"9/22/2026\",\"Released\",\"Inventory Reimbursement\",\"---\",\"FBA Inventory Reimbursement\",\"0\",\"0\",\"0\",\"15.14\",\"15.14\"\n"
            ."\"9/22/2026\",\"Released\",\"Inventory Reimbursement\",\"---\",\"FBA Inventory Reimbursement\",\"0\",\"0\",\"0\",\"15.14\",\"15.14\"\n"
            ."\"9/21/2026\",\"Deferred\",\"Order Payment\",\"114-9035629-8893059\",\"Purest Peak Himalayan Pink Salt Fine Gra...\",\"61.98\",\"0\",\"-19.56\",\"0\",\"42.42\"\n"
            ."\"9/19/2026\",\"Released\",\"Refund\",\"114-9438280-6100249\",\"Purest Peak Coarse Grain Himalayan Pink ...\",\"-30\",\"0\",\"2.4\",\"0\",\"-27.6\"\n"
            ."\"9/19/2026\",\"Released\",\"Order Payment\",\"114-9438280-6100249\",\"Purest Peak Coarse Grain Himalayan Pink ...\",\"30\",\"0\",\"-12.53\",\"0\",\"17.47\"\n"
            ."\"9/19/2026\",\"Released\",\"Service Fees\",\"---\",\"FBA Long Term Storage Fee\",\"0\",\"0\",\"-147.94\",\"0\",\"-147.94\"\n"
            ."\"9/14/2026\",\"Released\",\"Service Fees\",\"b4db4cbd-f358-4628-beb7-7aef81fe06bb\",\"Coupon Participation Fee\",\"0\",\"0\",\"-5\",\"0\",\"-5\"\n"
            ."\"8/21/2026\",\"Released\",\"Paid to Amazon | Seller repayment\",\"---\",\"Successful Charge\",\"0\",\"0\",\"0\",\"678.36\",\"678.36\"\n"
            ."\"8/20/2026\",\"Released\",\"Service Fees\",\"FBA19M9F42VL\",\"Inbound Transportation Fee\",\"0\",\"0\",\"0\",\"-276.15\",\"-276.15\"\n"
            ."\"8/18/2026\",\"Released\",\"Service Fees\",\"---\",\"Subscription Fee:\",\"0\",\"0\",\"-22.73\",\"0\",\"-22.73\"\n"
            ."\"8/12/2026\",\"Released\",\"Order Payment\",\"S01-7559260-4238019\",\"Purest Peak Himalayan Pink Salt, Fine Gr...\",\"0\",\"0\",\"-11.6\",\"0\",\"-11.6\"\n"
            ."\"8/3/2026\",\"Released\",\"Service Fees\",\"FBA19BD5L46V\",\"FBA Inbound Placement Service Fee\",\"0\",\"0\",\"0\",\"0\",\"0\"\n";
    }

    public function test_orders_fees_and_charges_are_recorded(): void
    {
        $import = $this->upload('Transactions for time period 7_1_2026 - 9_23_2026.csv', $this->csv())->assertCreated()->json();
        $this->assertSame(['amazon_transaction_view', 3, 7, 0], [$import['format'], $import['orders_created'], $import['expenses'], $import['units']]);
        $warnings = implode(' ', $import['warnings']);
        $this->assertStringContainsString('Deferred', $warnings);
        $this->assertStringContainsString('678.36', $warnings);
        $this->assertStringContainsString('no SKUs', $warnings);

        // Promotion offset by "Other": the buyer paid 11.99 for the product.
        $order = $this->store->orders()->where('external_id', '112-2483727-3749046')->sole();
        $this->assertSame([11.99, 7.46, 0.0, true, 0], [$order->subtotal, $order->channel_fees, $order->refunds, $order->fees_actual, $order->items()->count()]);
        // Refunded in the same file: refund 30, and the 2.40 of fees Amazon gave back.
        $refunded = $this->store->orders()->where('external_id', '114-9438280-6100249')->sole();
        $this->assertSame([30.0, 10.13, 30.0], [$refunded->subtotal, $refunded->channel_fees, $refunded->refunds]);

        $expenses = $this->user->expenses()->orderBy('amount')->get()->map(fn ($e) => [$e->category, $e->amount])->all();
        $this->assertSame([
            ['fees', -15.14], ['fees', -15.14], // two reimbursements on one day: both kept, as credits
            ['advertising', 5.0], ['shipping', 11.6], ['fees', 22.73], ['shipping', 147.94], ['shipping', 276.15],
        ], $expenses);
        $this->assertSame('USD', $this->store->fresh()->currency);
    }

    public function test_overlapping_exports_do_not_duplicate(): void
    {
        $this->upload('jul-sep.csv', $this->csv())->assertCreated();
        $this->upload('aug-sep.csv', $this->csv()."\"9/23/2026\",\"Released\",\"Order Payment\",\"113-9389613-6001811\",\"Purest Peak Himalayan Pink Salt, Fine Gr...\",\"5.99\",\"0\",\"-3.24\",\"0\",\"2.75\"\n")
            ->assertCreated()->assertJsonPath('orders_created', 1)->assertJsonPath('orders_updated', 3);

        $this->assertSame(4, $this->store->orders()->count());
        $this->assertSame(7, $this->user->expenses()->count());
    }

    public function test_combines_with_the_all_orders_report_in_either_order(): void
    {
        $this->post('/api/products/import', ['file' => UploadedFile::fake()->createWithContent('stock.csv', "sku,on_hand,unit_cost\nSALT-FINE,50,3\n")], ['Accept' => 'application/json'])->assertOk();
        $allOrders = "amazon-order-id\tpurchase-date\torder-status\tfulfillment-channel\tproduct-name\tsku\tquantity\tcurrency\titem-price\n"
            ."114-9035629-8893059\t2026-09-21T10:00:00+00:00\tShipped\tAmazon\tPink salt\tSALT-FINE\t2\tUSD\t61.98\n";

        // Orders report first: items and stock, with an estimated fee. Then the payments report sets the actual fee.
        $this->upload('all-orders.txt', $allOrders)->assertCreated();
        $this->assertSame(9.3, $this->store->orders()->sole()->channel_fees); // 15% estimate
        $this->upload('transactions.csv', $this->csv())->assertCreated();

        $order = $this->store->orders()->where('external_id', '114-9035629-8893059')->with('items')->sole();
        $this->assertSame([61.98, 19.56, true, 1, 6.0], [$order->subtotal, $order->channel_fees, $order->fees_actual, $order->items->count(), $order->cogs]);
        $this->assertSame(48, $this->user->products()->firstWhere('sku', 'SALT-FINE')->on_hand);

        // A newer orders report keeps the actual fee instead of going back to the estimate.
        $this->upload('all-orders-2.txt', $allOrders."\n")->assertCreated();
        $order->refresh();
        $this->assertSame([19.56, true], [$order->channel_fees, $order->fees_actual]);
        $this->assertSame(48, $this->user->products()->firstWhere('sku', 'SALT-FINE')->on_hand);
    }

    public function test_multi_channel_fulfillment_orders_take_stock_but_carry_no_amazon_sales(): void
    {
        $this->post('/api/products/import', ['file' => UploadedFile::fake()->createWithContent('stock.csv', "sku,on_hand\nKE-5RJ1-91ED,10\n")], ['Accept' => 'application/json'])->assertOk();
        $import = $this->upload('171808790067020719.txt', "amazon-order-id\tmerchant-order-id\tpurchase-date\tlast-updated-date\torder-status\tfulfillment-channel\tsales-channel\tproduct-name\tsku\tquantity\tcurrency\titem-price\titem-promotion-discount\torder-item-id \n"
            ."S01-1139933-0018804\tCONSUMER-2026811-15035\t2026-08-11T15:00:39+00:00\t2026-08-12T10:24:26+00:00\tShipped\tAmazon\tNon-Amazon US\tPink salt 3.5 lb\tKE-5RJ1-91ED\t1\t\t\t\tCONSUMER-2026811-15035-0\n"
            ."114-2679127-5219446\t114-2679127-5219446\t2026-08-14T13:19:57+00:00\t2026-08-15T02:39:52+00:00\tShipped\tAmazon\tAmazon.com\tPink salt 3.5 lb\tKE-5RJ1-91ED\t1\tUSD\t11.99\t\t167136252806401\n")
            ->assertCreated()->json();

        $mcf = $this->store->orders()->where('number', 'S01-1139933-0018804')->sole();
        $this->assertSame(['Multi-Channel Fulfillment CONSUMER-2026811-15035', 0.0, 0.0], [$mcf->customer_name, $mcf->subtotal, $mcf->channel_fees]);
        $this->assertSame(8, $this->user->products()->sole()->on_hand);
        $this->assertStringContainsString('Multi-Channel Fulfillment', implode(' ', $import['warnings']));
    }

    public function test_inventory_ledger_sets_stock_from_the_latest_sellable_balance(): void
    {
        $ledger = '"Date","FNSKU","ASIN","MSKU","Title","Disposition","Starting Warehouse Balance","In Transit Between Warehouses","Receipts","Customer Shipments","Customer Returns","Vendor Returns","Warehouse Transfer In/Out","Found","Lost","Damaged","Disposed","Other Events","Ending Warehouse Balance","Unknown Events","Location","Store"'."\n"
            ."\"09/13/2026\",\"X1\",\"B1\",\"PPVC16-GN\",\"Vegetable Chopper\",\"SELLABLE\",\"40\",\"0\",\"0\",\"-1\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"39\",\"0\",\"AUS2\",\n"
            ."\"09/14/2026\",\"X1\",\"B1\",\"PPVC16-GN\",\"Vegetable Chopper\",\"SELLABLE\",\"6\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"6\",\"0\",\"BOI2\",\n"
            ."\"09/14/2026\",\"X1\",\"B1\",\"PPVC16-GN\",\"Vegetable Chopper\",\"SELLABLE\",\"28\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"28\",\"0\",\"JAX7\",\n"
            ."\"09/14/2026\",\"X2\",\"B2\",\"TZ-UJKI-V1HE\",\"Pink Salt\",\"SELLABLE\",\"10\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"10\",\"0\",\"AKC1\",\n"
            ."\"09/14/2026\",\"X2\",\"B2\",\"TZ-UJKI-V1HE\",\"Pink Salt\",\"EXPIRED\",\"33\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"33\",\"0\",\"AKC1\",\n"
            ."\"03/21/2026\",\"X3\",\"B3\",\"FAUWH24\",\"Faucet Mat White\",\"SELLABLE\",\"1\",\"0\",\"0\",\"-1\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"0\",\"MLI1\",\n";

        $summary = $this->post('/api/products/import', ['file' => UploadedFile::fake()->createWithContent('Inventory Ledger.csv', $ledger)], ['Accept' => 'application/json'])->assertOk()->json();

        $stock = $this->user->products()->orderBy('sku')->get()->mapWithKeys(fn ($p) => [$p->sku => [$p->on_hand, $p->warehouse]])->all();
        $this->assertSame(['FAUWH24' => [0, 'Amazon FBA'], 'PPVC16-GN' => [34, 'Amazon FBA'], 'TZ-UJKI-V1HE' => [10, 'Amazon FBA']], $stock);
        $this->assertStringContainsString('TZ-UJKI-V1HE (33)', implode(' ', $summary['warnings']));
    }

    public function test_payments_report_first_then_orders_report_adds_items(): void
    {
        $this->post('/api/products/import', ['file' => UploadedFile::fake()->createWithContent('stock.csv', "sku,on_hand\nSALT-FINE,50\n")], ['Accept' => 'application/json'])->assertOk();
        $this->upload('transactions.csv', $this->csv())->assertCreated();

        $this->upload('all-orders.txt', "amazon-order-id\tpurchase-date\torder-status\tfulfillment-channel\tproduct-name\tsku\tquantity\tcurrency\titem-price\n"
            ."114-9035629-8893059\t2026-09-21T10:00:00+00:00\tShipped\tAmazon\tPink salt\tSALT-FINE\t2\tUSD\t61.98\n")->assertCreated();

        $order = $this->store->orders()->where('external_id', '114-9035629-8893059')->with('items')->sole();
        $this->assertSame([19.56, 1], [$order->channel_fees, $order->items->count()]);
        $this->assertSame(48, $this->user->products()->firstWhere('sku', 'SALT-FINE')->on_hand);
    }
}
