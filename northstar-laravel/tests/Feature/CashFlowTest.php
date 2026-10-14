<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Services\DemoWorkspace;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CashFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->travelTo('2026-07-20 12:00:00');
        $this->user = User::factory()->create();
        Ledger::setupChartOfAccounts($this->user);
        $this->actingAs($this->user, 'sanctum');
    }

    private function store(string $platform, string $name): Channel
    {
        return Channel::find($this->postJson('/api/channels', ['platform' => $platform, 'name' => $name, 'connection' => 'statements'])->json('channel.id'));
    }

    private function upload(Channel $store, string $name, string $content): void
    {
        $this->post("/api/channels/{$store->id}/statements", ['file' => UploadedFile::fake()->createWithContent($name, $content)], ['Accept' => 'application/json'])->assertCreated();
    }

    private function line(array $statement, string $key): ?array
    {
        foreach ($statement['sections'] as $section) {
            foreach ($section['lines'] as $line) {
                if ($line['key'] === $key) {
                    return $line;
                }
            }
        }

        return null;
    }

    public function test_payouts_drive_cash_and_marketplace_deductions_are_not_counted_twice(): void
    {
        $shopify = $this->store('Shopify', 'WTN Essentials');
        $this->upload($shopify, 'payout.csv', "Payout Date,Status,Charges,Refunds,Adjustments,Marketplace Sales Tax,Advances,Reserved Funds,Fees,Retried Amount,Total,Currency,Bank Reference\n"
            ."2026-07-19,scheduled,12.11,0.00,0.00,0.00,0.00,0.00,0.91,0.00,11.20,USD,\n"
            ."2026-07-17,paid,554.11,-22.87,0.00,-14.75,0.00,0.00,24.41,0.00,492.08,USD,\n"
            ."2026-06-12,paid,219.79,-111.50,-44.70,-28.70,0.00,0.00,24.01,0.00,10.88,USD,\n"
            ."2025-12-31,paid,755.81,-358.23,-166.13,-34.19,0.00,0.00,36.30,0.00,160.96,USD,\n");
        // A Shop campaign charge is deducted inside the payouts: it's an expense in the P&L, but not extra cash out.
        $this->upload($shopify, 'tx.csv', "Transaction Date,Type,Order,Card Brand,Card Source,Payout Status,Payout Date,Payout ID,Available On,Amount,Fee,Net,Checkout,Payment Method Name,Presentment Amount,Presentment Currency,Currency\n"
            ."2026-07-02 01:58:35 -0400,shop_cash_campaign_billing_debit,2541813,unknown,online,paid,2026-07-04,1,2026-07-02,-14.31,0.00,-14.31,,,,,USD\n");

        $this->postJson('/api/expenses', ['category' => 'software', 'amount' => 39, 'incurred_on' => '2026-07-01', 'description' => 'Apps'])->assertCreated();
        $this->postJson('/api/finance/journal-entries', ['date' => '2026-02-01', 'description' => 'Owner investment', 'lines' => [
            ['ledger_account_id' => Ledger::account($this->user, '1000')->id, 'debit' => 1000],
            ['ledger_account_id' => Ledger::account($this->user, '3000')->id, 'credit' => 1000],
        ]])->assertCreated();

        $cf = $this->getJson('/api/finance/cash-flow?period=last_3_months&group=month&method=direct')->assertOk()->json();

        // Opening = everything before May: the Dec payout and the owner's investment.
        $this->assertSame(1160.96, $cf['summary']['opening']);
        // In the period: two paid payouts (10.88 + 492.08) less the software bill. The scheduled payout isn't cash yet.
        $this->assertSame(463.96, $cf['summary']['net']);
        $this->assertSame(1624.92, $cf['summary']['closing']);
        $this->assertSame(11.2, $cf['summary']['pending_payouts']);

        $this->assertSame(773.9, $this->line($cf, 'customer_payments')['total']);
        $this->assertSame(-48.42, $this->line($cf, 'fees')['total']);
        $this->assertEquals(-39, $this->line($cf, 'expenses')['total']);
        $this->assertNull($this->line($cf, 'owner'));
        $this->assertEquals([0, 10.88, 453.08], $cf['net_change']['values']); // May, June, July
        $this->assertSame(1624.92, end($cf['months'])['closing']);
    }

    public function test_stores_without_payment_data_are_estimated_from_orders(): void
    {
        $amazon = $this->store('Amazon', 'Amazon US');
        $this->upload($amazon, 'all-orders.txt', "amazon-order-id\tpurchase-date\torder-status\tfulfillment-channel\tproduct-name\tsku\tquantity\tcurrency\titem-price\n"
            ."111-1\t2026-07-05T10:00:00+00:00\tShipped\tMerchant\tMug\tMUG\t2\tUSD\t40.00\n");

        $cf = $this->getJson('/api/finance/cash-flow?period=this_month&group=channel')->assertOk()->json();

        // 40 sales less the 15% estimated Amazon fee.
        $this->assertEquals(34, $cf['summary']['net']);
        $this->assertStringContainsString('Amazon US', $cf['notes'][0]);
        $this->assertSame(['Amazon US', 'Shared'], array_column($cf['columns'], 'label'));
    }

    public function test_statement_for_one_store_includes_only_that_stores_cash(): void
    {
        $shopify = $this->store('Shopify', 'WTN Essentials');
        $amazon = $this->store('Amazon', 'Amazon US');
        $this->upload($shopify, 'payout.csv', "Payout Date,Status,Charges,Refunds,Adjustments,Marketplace Sales Tax,Advances,Reserved Funds,Fees,Retried Amount,Total,Currency,Bank Reference\n"
            ."2026-07-19,scheduled,12.11,0.00,0.00,0.00,0.00,0.00,0.91,0.00,11.20,USD,\n"
            ."2026-07-17,paid,554.11,-22.87,0.00,-14.75,0.00,0.00,24.41,0.00,492.08,USD,\n");
        $this->upload($amazon, 'all-orders.txt', "amazon-order-id\tpurchase-date\torder-status\tfulfillment-channel\tproduct-name\tsku\tquantity\tcurrency\titem-price\n"
            ."111-1\t2026-07-05T10:00:00+00:00\tShipped\tMerchant\tMug\tMUG\t2\tUSD\t40.00\n");
        $this->postJson('/api/expenses', ['category' => 'software', 'channel_id' => $shopify->id, 'amount' => 29, 'incurred_on' => '2026-07-03'])->assertCreated();
        $this->postJson('/api/expenses', ['category' => 'software', 'amount' => 10, 'incurred_on' => '2026-07-03', 'description' => 'Shared tool'])->assertCreated();

        $cf = $this->getJson("/api/finance/cash-flow?period=this_month&group=channel&channel_id={$shopify->id}")->assertOk()->json();

        $this->assertSame('WTN Essentials', $cf['store']['name']);
        $this->assertSame('month', $cf['group']);
        // The Shopify payout less the Shopify-only expense; Amazon's sales and the shared tool are left out.
        $this->assertEquals(463.08, $cf['summary']['net']);
        $this->assertEquals(11.2, $cf['summary']['pending_payouts']);
        $this->assertStringContainsString('10.00', $cf['notes'][0]);

        $this->getJson('/api/finance/cash-flow?channel_id=999999')->assertNotFound();
        $this->get("/api/finance/cash-flow/export?period=this_month&channel_id={$shopify->id}")->assertOk()
            ->assertDownload('northstar-cash-flow-wtn-essentials-2026-07-01.csv');
    }

    public function test_receivables_are_sales_earned_but_not_yet_paid_out(): void
    {
        $shopify = $this->store('Shopify', 'WTN Essentials');
        $header = "Transaction Date,Type,Order,Card Brand,Card Source,Payout Status,Payout Date,Payout ID,Available On,Amount,Fee,Net,Checkout,Payment Method Name,Presentment Amount,Presentment Currency,Currency\n";
        $this->upload($shopify, 'tx.csv', $header
            // Paid out in the 2026-06-30 payout.
            ."2026-06-29 10:00:00 -0400,charge,1001,visa,online,paid,2026-06-30,1,2026-06-29,100.00,3.20,96.80,#1,card,100.00,USD,USD\n"
            ."2026-06-29 10:00:01 -0400,marketplace sales tax,1001,unknown,online,paid,2026-06-30,1,2026-06-29,-6.80,0.00,-6.80,,,,,USD\n"
            // Earned in July, not paid out yet.
            ."2026-07-16 22:28:22 -0400,charge,1002,unknown,online,scheduled,2026-07-19,2,2026-07-17,12.11,0.91,11.20,#2,paypal,12.11,USD,USD\n"
            ."2026-07-17 05:46:27 -0400,charge,1003,visa,online,pending,2026-07-22,,2026-07-22,41.03,1.49,39.54,#3,card,41.03,USD,USD\n");
        $this->upload($shopify, 'payout.csv', "Payout Date,Status,Charges,Refunds,Adjustments,Marketplace Sales Tax,Advances,Reserved Funds,Fees,Retried Amount,Total,Currency,Bank Reference\n"
            ."2026-07-19,scheduled,12.11,0.00,0.00,0.00,0.00,0.00,0.91,0.00,11.20,USD,\n"
            ."2026-06-30,paid,100.00,0.00,0.00,-6.80,0.00,0.00,3.20,0.00,90.00,USD,\n");

        $r = $this->getJson('/api/finance/cash-flow?period=this_month')->assertOk()->json('receivables');

        $this->assertTrue($r['tracked']);
        $this->assertEquals(0, $r['opening']);
        $this->assertEquals(50.74, $r['earned']['total']);
        $this->assertEquals(0, $r['collected']['total']);
        $this->assertEquals(50.74, $r['closing']);
        $this->assertSame([['2026-07-19', 'scheduled', 11.2], ['2026-07-22', 'pending', 39.54]],
            array_map(fn ($o) => [$o['date'], $o['status'], $o['amount']], $r['outstanding']));
        $this->assertSame('2026-07-22', $r['next_payout']);
        $this->assertSame([], $r['notes']);

        // Last month: earned 90.00 net and it was all paid out on the 30th.
        $june = $this->getJson('/api/finance/cash-flow?period=last_month')->json('receivables');
        $this->assertEquals([0, 90, -90, 0], [$june['opening'], $june['earned']['total'], $june['collected']['total'], $june['closing']]);
    }

    public function test_stores_with_only_payouts_owe_their_scheduled_payouts(): void
    {
        $shopify = $this->store('Shopify', 'WTN Essentials');
        $this->upload($shopify, 'payout.csv', "Payout Date,Status,Charges,Refunds,Adjustments,Marketplace Sales Tax,Advances,Reserved Funds,Fees,Retried Amount,Total,Currency,Bank Reference\n"
            ."2026-07-21,scheduled,50.00,0.00,0.00,0.00,0.00,0.00,1.50,0.00,48.50,USD,\n"
            ."2026-07-17,paid,554.11,-22.87,0.00,-14.75,0.00,0.00,24.41,0.00,492.08,USD,\n");
        $amazon = $this->store('Amazon', 'Amazon US');

        $r = $this->getJson('/api/finance/cash-flow?period=this_month')->json('receivables');
        $this->assertEquals(48.5, $r['closing']);
        $this->assertSame([['2026-07-21', 'scheduled', 48.5]], array_map(fn ($o) => [$o['date'], $o['status'], $o['amount']], $r['outstanding']));

        $this->assertFalse($this->getJson("/api/finance/cash-flow?period=this_month&channel_id={$amazon->id}")->json('receivables.tracked'));
    }

    public function test_indirect_method_starts_from_net_profit_and_reaches_the_same_cash(): void
    {
        // A store with orders only: profit 34 (40 sales less the 15% fee) less 8 cost of goods; stock bought for 30.
        $amazon = $this->store('Amazon', 'Amazon US');
        $this->post('/api/products/import', ['file' => UploadedFile::fake()->createWithContent('stock.csv', "sku,on_hand,unit_cost\nMUG,10,4\n")], ['Accept' => 'application/json'])->assertOk();
        $mug = $this->user->products()->sole();
        $this->postJson('/api/products/adjust', ['product_ids' => [$mug->id], 'reason' => 'Receiving', 'quantity' => 5])->assertOk(); // 5 × 4 = 20 bought
        $this->upload($amazon, 'all-orders.txt', "amazon-order-id\tpurchase-date\torder-status\tfulfillment-channel\tproduct-name\tsku\tquantity\tcurrency\titem-price\n"
            ."111-1\t2026-07-05T10:00:00+00:00\tShipped\tMerchant\tMug\tMUG\t2\tUSD\t40.00\n");
        // A Shopify store with payments: 50.74 earned, none paid out yet.
        $shopify = $this->store('Shopify', 'WTN Essentials');
        $this->upload($shopify, 'tx.csv', "Transaction Date,Type,Order,Card Brand,Card Source,Payout Status,Payout Date,Payout ID,Available On,Amount,Fee,Net,Checkout,Payment Method Name,Presentment Amount,Presentment Currency,Currency\n"
            ."2026-07-16 22:28:22 -0400,charge,1002,unknown,online,scheduled,2026-07-19,2,2026-07-17,12.11,0.91,11.20,#2,paypal,12.11,USD,USD\n"
            ."2026-07-17 05:46:27 -0400,charge,1003,visa,online,pending,2026-07-22,,2026-07-22,41.03,1.49,39.54,#3,card,41.03,USD,USD\n");

        $indirect = $this->getJson('/api/finance/cash-flow?period=this_month')->assertOk()->json();
        $direct = $this->getJson('/api/finance/cash-flow?period=this_month&method=direct')->json();
        $lines = collect($indirect['sections'][0]['lines'])->pluck('total', 'key')->all();

        $this->assertSame('indirect', $indirect['method']);
        $profit = $this->getJson('/api/finance/profit-loss?period=this_month')->json('summary.net_profit.total');
        $this->assertEquals($profit, $lines['net_profit']);
        $this->assertEquals(76.74, $lines['net_profit']);  // Amazon 34 - 8 COGS = 26, plus Shopify 50.74
        // Stock sold at cost 8, stock bought 20: inventory grew by 12, which profit doesn't show but cash does.
        $this->assertEquals(-12, $lines['inventory_change']);
        $this->assertEquals(-50.74, $lines['receivables']);
        $this->assertArrayNotHasKey('timing', $lines);
        // Same cash either way: 34 from Amazon, less 20 of stock; Shopify hasn't paid out.
        $this->assertEquals(14, $indirect['sections'][0]['total']);
        $this->assertEquals($direct['sections'][0]['total'], $indirect['sections'][0]['total']);
        $this->assertEquals($direct['summary']['closing'], $indirect['summary']['closing']);
    }

    public function test_statement_is_internally_consistent_on_a_full_workspace(): void
    {
        DemoWorkspace::seed($this->user);

        foreach (['month', 'channel'] as $group) {
            $cf = $this->getJson("/api/finance/cash-flow?period=last_6_months&group={$group}")->assertOk()->json();
            $sections = array_sum(array_column($cf['sections'], 'total'));
            $this->assertEqualsWithDelta($cf['net_change']['total'], $sections, 0.05);
            $this->assertEqualsWithDelta($cf['net_change']['total'], array_sum($cf['net_change']['values']), 0.05);
            $this->assertEqualsWithDelta($cf['summary']['closing'], $cf['summary']['opening'] + $cf['summary']['net'], 0.01);
            foreach ($cf['sections'] as $section) {
                foreach ($section['lines'] as $line) {
                    if ($line['children']) {
                        $this->assertEqualsWithDelta($line['total'], array_sum(array_column($line['children'], 'total')), 0.05);
                    }
                }
            }
        }

        $this->get('/api/finance/cash-flow/export?period=last_6_months')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
}
