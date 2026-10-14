<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StatementImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    private function store(string $platform, string $name): Channel
    {
        $id = $this->postJson('/api/channels', ['platform' => $platform, 'name' => $name, 'connection' => 'statements'])->assertCreated()->json('channel.id');

        return Channel::find($id);
    }

    private function upload(Channel $channel, string $filename, string $content, ?string $format = null)
    {
        return $this->post("/api/channels/{$channel->id}/statements", array_filter([
            'file' => UploadedFile::fake()->createWithContent($filename, $content),
            'format' => $format,
        ]), ['Accept' => 'application/json']);
    }

    private function openingStock(string $csv): void
    {
        $this->post('/api/products/import', ['file' => UploadedFile::fake()->createWithContent('stock.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_several_stores_per_platform_can_be_added(): void
    {
        $this->store('Shopify', 'Purest Peaks');
        $this->store('Shopify', 'Peak Outlet');
        $this->store('Amazon', 'Amazon US');
        $this->store('Amazon', 'Amazon UK');

        $this->postJson('/api/channels', ['platform' => 'Amazon', 'name' => 'Amazon US', 'connection' => 'statements'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame(['Amazon', 'Amazon', 'Shopify', 'Shopify'], collect($this->getJson('/api/channels')->json())->pluck('platform')->all());
        $this->assertSame('statements', $this->getJson('/api/channels')->json('0.connection'));
    }

    public function test_shopify_orders_export_creates_orders_and_deducts_stock(): void
    {
        $this->openingStock("sku,name,on_hand,unit_cost,price\nTEE-M,Tee M,50,8,25\nMUG,Mug,10,4,18\n");
        $store = $this->store('Shopify', 'Purest Peaks');

        $csv = <<<'CSV'
            Name,Email,Financial Status,Fulfillment Status,Currency,Subtotal,Shipping,Total,Created at,Lineitem quantity,Lineitem name,Lineitem price,Lineitem sku,Shipping Name,Shipping Address1,Shipping City,Shipping Zip,Shipping Province,Shipping Country,Cancelled at,Refunded Amount
            #1001,ana@example.com,paid,fulfilled,USD,68.00,5.00,73.00,2026-10-01 10:00:00 -0400,2,Tee - M,25.00,TEE-M,Ana Lee,1 Main St,Austin,78701,TX,US,,0.00
            #1001,,,,,,,,2026-10-01 10:00:00 -0400,1,Mug,18.00,MUG,,,,,,,,
            #1002,bo@example.com,paid,unfulfilled,USD,25.00,0,25.00,2026-10-02 09:00:00 -0400,1,Tee - M,25.00,TEE-M,Bo Kim,2 Oak Ave,Boston,02101,MA,US,,0.00
            #1003,cy@example.com,refunded,,USD,18.00,0,18.00,2026-10-03 09:00:00 -0400,3,Mug,18.00,MUG,Cy Doe,3 Elm St,Denver,80202,CO,US,2026-10-03 12:00:00 -0400,18.00
            CSV;

        $import = $this->upload($store, 'orders_export.csv', $csv)->assertCreated()->json();
        $this->assertSame(['shopify_orders', 3, 0, 4], [$import['format'], $import['orders_created'], $import['orders_updated'], $import['units']]);

        $order = $store->orders()->where('number', '#1001')->with('items')->sole();
        $this->assertSame(['shipped', 'Ana Lee', 68.0, 20.0, 2], [$order->status, $order->customer_name, $order->subtotal, $order->cogs, $order->items->count()]);
        $this->assertSame('cancelled', $store->orders()->where('number', '#1003')->value('status'));

        // 3 tees sold from 50; the cancelled mug order doesn't take stock.
        $this->assertSame(47, $this->user->products()->firstWhere('sku', 'TEE-M')->on_hand);
        $this->assertSame(9, $this->user->products()->firstWhere('sku', 'MUG')->on_hand);
        $this->assertSame('USD', $store->fresh()->currency);
    }

    public function test_reuploading_overlapping_reports_does_not_double_count(): void
    {
        $this->openingStock("sku,on_hand\nTEE-M,50\n");
        $store = $this->store('Shopify', 'Purest Peaks');
        $header = "Name,Financial Status,Fulfillment Status,Created at,Lineitem quantity,Lineitem name,Lineitem price,Lineitem sku\n";

        $this->upload($store, 'week1.csv', $header."#1,paid,unfulfilled,2026-10-01 10:00:00,2,Tee,25,TEE-M\n")->assertCreated();
        $this->upload($store, 'week1.csv', $header."#1,paid,unfulfilled,2026-10-01 10:00:00,2,Tee,25,TEE-M\n")
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        // A later export includes order #1 again (now 3 units) and a new order #2.
        $later = $this->upload($store, 'month.csv', $header."#1,paid,fulfilled,2026-10-01 10:00:00,3,Tee,25,TEE-M\n#2,paid,unfulfilled,2026-10-05 10:00:00,1,Tee,25,TEE-M\n")
            ->assertCreated()->json();
        $this->assertSame([1, 1], [$later['orders_created'], $later['orders_updated']]);
        $this->assertSame(2, $store->orders()->count());
        $this->assertSame(46, $this->user->products()->firstWhere('sku', 'TEE-M')->on_hand);

        // Deleting the later upload removes order #2 and the order #1 version it wrote, returning their units.
        $this->deleteJson("/api/statement-imports/{$later['id']}")->assertOk();
        $this->assertSame(0, $store->orders()->count());
        $this->assertSame(50, $this->user->products()->firstWhere('sku', 'TEE-M')->on_hand);
    }

    public function test_same_order_number_in_two_shopify_stores_is_kept_separately(): void
    {
        $a = $this->store('Shopify', 'Store A');
        $b = $this->store('Shopify', 'Store B');
        $csv = "Name,Financial Status,Fulfillment Status,Created at,Lineitem quantity,Lineitem name,Lineitem price,Lineitem sku\n#1001,paid,,2026-10-01 10:00:00,1,Tee,25,TEE-M\n";

        $this->upload($a, 'a.csv', $csv)->assertCreated();
        $this->upload($b, 'b.csv', $csv."\n")->assertCreated();

        $this->assertSame(1, $a->orders()->count());
        $this->assertSame(1, $b->orders()->count());
    }

    public function test_amazon_transaction_report_records_fees_refunds_and_expenses(): void
    {
        $this->openingStock("sku,on_hand,unit_cost\nNS-MUG,100,6\n");
        $store = $this->store('Amazon', 'Amazon US');

        $csv = <<<'CSV'
            "Includes Amazon Marketplace, Fulfillment by Amazon (FBA), and Amazon Webstore transactions"
            "All amounts in USD, unless specified"
            "date/time","settlement id","type","order id","sku","description","quantity","marketplace","fulfillment","order city","order state","order postal","tax collection model","product sales","product sales tax","shipping credits","shipping credits tax","gift wrap credits","giftwrap credits tax","promotional rebates","promotional rebates tax","marketplace withheld tax","selling fees","fba fees","other transaction fees","other","total"
            "Oct 1, 2026 9:15:02 AM PDT","123","Order","111-0000001-0000001","NS-MUG","Ceramic mug","2","amazon.com","Amazon","AUSTIN","TX","78701","MarketplaceFacilitator","40.00","3.20","0","0","0","0","-4.00","0","-3.20","-5.40","-7.10","0","0","23.50"
            "Oct 3, 2026 2:00:00 PM PDT","123","Refund","111-0000001-0000001","NS-MUG","Ceramic mug","1","amazon.com","Amazon","AUSTIN","TX","78701","MarketplaceFacilitator","-20.00","-1.60","0","0","0","0","2.00","0","1.60","2.16","0","0","0","-15.84"
            "Oct 4, 2026 1:00:00 AM PDT","123","Refund","111-9999999-9999999","NS-MUG","Ceramic mug","1","amazon.com","Amazon","","","","","-10.00","0","0","0","0","0","0","0","0","1.20","0","0","0","-8.80"
            "Oct 5, 2026 1:00:00 AM PDT","123","Service Fee","","","Subscription","","amazon.com","","","","","","0","0","0","0","0","0","0","0","0","0","0","0","-39.99","-39.99"
            "Oct 6, 2026 1:00:00 AM PDT","123","Service Fee","","","Cost of Advertising","","amazon.com","","","","","","0","0","0","0","0","0","0","0","0","0","0","0","-25.00","-25.00"
            "Oct 7, 2026 1:00:00 AM PDT","123","FBA Inventory Fee","","","FBA storage fee","","amazon.com","","","","","","0","0","0","0","0","0","0","0","0","0","0","0","-12.30","-12.30"
            "Oct 8, 2026 1:00:00 AM PDT","123","Transfer","","","To account ending in: 123","","amazon.com","","","","","","0","0","0","0","0","0","0","0","0","0","0","0","-500.00","-500.00"
            CSV;

        $import = $this->upload($store, 'Oct2026MonthlyTransaction.csv', $csv)->assertCreated()->json();
        $this->assertSame(['amazon_transactions', 2, 3], [$import['format'], $import['orders_created'], $import['expenses']]);

        $order = $store->orders()->where('external_id', '111-0000001-0000001')->sole();
        // 40 sales - 4 rebate; fees 5.40 + 7.10 - 2.16 refunded commission; refund 20 - 2 rebate.
        $this->assertSame([36.0, 10.34, 18.0, 'FBA'], [$order->subtotal, $order->channel_fees, $order->refunds, $order->fulfillment]);
        $this->assertSame(98, $this->user->products()->firstWhere('sku', 'NS-MUG')->on_hand);

        $expenses = $this->user->expenses()->orderBy('amount')->get();
        $this->assertSame([['shipping', 12.3], ['advertising', 25.0], ['fees', 39.99]], $expenses->map(fn ($e) => [$e->category, $e->amount])->all());

        // The same charges in a later, overlapping report are not added twice.
        $this->upload($store, 'Oct2026-reupload.csv', $csv."\n")->assertCreated();
        $this->assertSame(3, $this->user->expenses()->count());
        $this->assertSame(98, $this->user->products()->firstWhere('sku', 'NS-MUG')->on_hand);
    }

    public function test_amazon_orders_etsy_and_ebay_reports_are_detected(): void
    {
        $amazon = $this->store('Amazon', 'Amazon UK');
        $tsv = "amazon-order-id\tmerchant-order-id\tpurchase-date\torder-status\tfulfillment-channel\tproduct-name\tsku\tquantity\tcurrency\titem-price\tship-city\tship-country\n"
            ."202-1\t\t2026-10-01T10:00:00+00:00\tShipped\tMerchant\tTote, natural\tTOTE\t2\tGBP\t30.00\tLondon\tGB\n"
            ."202-2\t\t2026-10-02T10:00:00+00:00\tCancelled\tAmazon\tTote, natural\tTOTE\t0\tGBP\t0.00\tLeeds\tGB\n";
        $this->upload($amazon, 'all-orders.txt', $tsv)->assertCreated()->assertJsonPath('format', 'amazon_orders');
        $this->assertSame([15.0, 'GBP'], [$amazon->orders()->where('external_id', '202-1')->sole()->items()->value('unit_price'), $amazon->fresh()->currency]);

        $etsy = $this->store('Etsy', 'Etsy Goods');
        $csv = "Sale Date,Item Name,Buyer,Quantity,Price,Item Total,Currency,Transaction ID,Listing ID,Order ID,Ship Name,Ship City,Ship Country,SKU\n"
            ."10/04/26,Linen napkins,jdoe,2,12.00,24.00,USD,1,2,3001,Jane Doe,Portland,United States,NAP-1\n";
        $this->upload($etsy, 'EtsySoldOrderItems2026-10.csv', $csv)->assertCreated()->assertJsonPath('format', 'etsy_order_items');
        $this->assertSame('2026-10-04', $etsy->orders()->sole()->placed_at->toDateString());

        $ebay = $this->store('eBay', 'eBay Main');
        $csv = "\nSales Record Number,Order Number,Buyer Username,Buyer Name,Item Number,Item Title,Custom Label,Quantity,Sold For,Shipping And Handling,Total Price,Sale Date,Shipped On Date\n"
            ."1,12-345,bob1,Bob Ray,,,,,,4.00,54.00,Oct-05-26,Oct-06-26\n"
            ."1,12-345,bob1,Bob Ray,111,Camp stove,STOVE,1,30.00,,,Oct-05-26,Oct-06-26\n"
            ."1,12-345,bob1,Bob Ray,112,Fuel can,FUEL,2,10.00,,,Oct-05-26,Oct-06-26\n"
            ."\n3 record(s) downloaded,from Oct-01-26 to Oct-31-26\n";
        $this->upload($ebay, 'eBay-OrdersReport.csv', $csv)->assertCreated()->assertJsonPath('format', 'ebay_orders');
        $order = $ebay->orders()->with('items')->sole();
        $this->assertSame([50.0, 'shipped', 2, '2026-10-05'], [$order->subtotal, $order->status, $order->items->count(), $order->placed_at->toDateString()]);
    }

    public function test_ebay_transaction_report_uses_actual_fees(): void
    {
        $ebay = $this->store('eBay', 'eBay Main');
        $csv = "Transaction report\n\nTransaction creation date,Type,Order number,Legacy order ID,Buyer username,Buyer name,Item ID,Transaction ID,Item title,Custom label,Quantity,Item subtotal,Final Value Fee - fixed,Final Value Fee - variable,Regulatory operating fee,Net amount,Transaction currency,Description\n"
            ."\"Oct 5, 2026\",Order,12-345,,bob1,Bob Ray,111,t1,Camp stove,STOVE,1,30.00,-0.40,-3.99,-0.12,25.49,USD,\n"
            ."\"Oct 6, 2026\",Shipping label,,,,,,,,,,,,,,-6.50,USD,USPS label\n"
            ."\"Oct 9, 2026\",Payout,,,,,,,,,,,,,,-100.00,USD,Payout\n";

        $this->upload($ebay, 'transactions.csv', $csv)->assertCreated()->assertJsonPath('format', 'ebay_transactions');
        $this->assertSame(4.51, $ebay->orders()->sole()->channel_fees);
        $this->assertSame([['shipping', 6.5]], $this->user->expenses()->get()->map(fn ($e) => [$e->category, $e->amount])->all());
    }

    public function test_generic_template_and_unknown_skus(): void
    {
        $store = $this->store('Etsy', 'Etsy Goods');
        $csv = "order_id,date,sku,product_name,quantity,unit_price,fees,shipping_cost,refund,status,customer_name,customer_email\n"
            ."A1,2026-10-01,NEW-SKU,Brand new thing,2,10.00,1.50,3.00,0,delivered,Jo,jo@example.com\n"
            ."A1,2026-10-01,,Unlisted item,1,5.00,,,,,,\n";

        $import = $this->upload($store, 'manual.csv', $csv)->assertCreated()->json();
        $this->assertSame([1, 1], [$import['orders_created'], $import['products_created']]);
        $this->assertNotEmpty($import['warnings']);

        $order = $store->orders()->sole();
        $this->assertSame([25.0, 1.5, 3.0, 'delivered'], [$order->subtotal, $order->channel_fees, $order->shipping_cost, $order->status]);
        $this->assertSame('Brand new thing', $this->user->products()->firstWhere('sku', 'NEW-SKU')->name);
    }

    public function test_reports_for_another_platform_or_unknown_layouts_are_rejected(): void
    {
        $amazon = $this->store('Amazon', 'Amazon US');
        $shopifyCsv = "Name,Created at,Lineitem quantity,Lineitem sku\n#1,2026-10-01,1,TEE\n";

        $this->upload($amazon, 'shopify.csv', $shopifyCsv)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload($amazon, 'random.csv', "foo,bar\n1,2\n")->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(0, $amazon->statementImports()->count());
    }

    public function test_stock_import_sets_counts_and_sums_locations(): void
    {
        $this->openingStock("Handle,Title,Option1 Name,Option1 Value,SKU,Location,On hand (current)\ntee,Everyday tee,Size,M,TEE-M,Shop,5\ntee,Everyday tee,Size,M,TEE-M,Warehouse,7\n");
        $product = $this->user->products()->sole();
        $this->assertSame(['Everyday tee / M', 12], [$product->name, $product->on_hand]);

        $summary = $this->post('/api/products/import', ['file' => UploadedFile::fake()->createWithContent('count.csv', "sku,on_hand\nTEE-M,9\n")], ['Accept' => 'application/json'])
            ->assertOk()->json();
        $this->assertSame([0, 1], [$summary['created'], $summary['updated']]);
        $this->assertSame(9, $product->fresh()->on_hand);
        $this->assertSame(-3, $product->movements()->latest('id')->first()->quantity);
    }
}
