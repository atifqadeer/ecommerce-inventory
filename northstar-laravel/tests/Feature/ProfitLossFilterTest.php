<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** P&L for one store or every store on a platform. */
class ProfitLossFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private array $stores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-20 12:00:00');
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');

        foreach ([['Shopify', 'Shop A'], ['Shopify', 'Shop B'], ['Amazon', 'Amazon US']] as [$platform, $name]) {
            $this->stores[$name] = $this->user->channels()->create(['platform' => $platform, 'name' => $name, 'handle' => '', 'color' => '#000', 'status' => 'connected']);
        }
        // Sales 600 / 300 / 100 this month, 100 for Shop A last month.
        foreach ([['Shop A', 600, 60, '2026-07-05'], ['Shop B', 300, 30, '2026-07-06'], ['Amazon US', 100, 15, '2026-07-07'], ['Shop A', 100, 10, '2026-06-10']] as $i => [$store, $sales, $fees, $date]) {
            $this->user->orders()->create(['channel_id' => $this->stores[$store]->id, 'number' => "#{$i}", 'customer_name' => 'A', 'status' => 'shipped',
                'subtotal' => $sales, 'channel_fees' => $fees, 'placed_at' => "{$date} 12:00:00"]);
        }
        // A store's own ad spend, and a 100 software bill shared by every store.
        $this->user->expenses()->create(['channel_id' => $this->stores['Shop A']->id, 'category' => 'advertising', 'amount' => 50, 'incurred_on' => '2026-07-08']);
        $this->user->expenses()->create(['channel_id' => null, 'category' => 'software', 'amount' => 100, 'incurred_on' => '2026-07-01']);
    }

    private function net(array $query): float
    {
        return $this->getJson('/api/finance/profit-loss?'.http_build_query(['period' => 'this_month'] + $query))->assertOk()->json('summary.net_profit.total');
    }

    public function test_one_store_gets_its_own_costs_and_its_share_of_shared_costs(): void
    {
        $pl = $this->getJson('/api/finance/profit-loss?period=this_month&channel_id='.$this->stores['Shop A']->id)->assertOk()->json();

        $this->assertSame('Shop A', $pl['filter']);
        $this->assertSame(['Shop A'], array_column($pl['columns'], 'label'));
        // 600 sales - 60 fees - 50 ads - 60 (60% of the shared 100).
        $this->assertEquals(430, $pl['summary']['net_profit']['total']);
        $this->assertEquals(600, $pl['summary']['revenue']['total']);
        $this->assertEquals(100, $pl['summary']['revenue']['previous']); // last month, this store only
    }

    public function test_store_statements_match_the_by_channel_view_and_add_up(): void
    {
        $byChannel = $this->getJson('/api/finance/profit-loss?period=this_month&group=channel')->json();
        $netLine = collect($byChannel['lines'])->firstWhere('key', 'net_profit');

        foreach ($byChannel['columns'] as $i => $column) {
            $this->assertEqualsWithDelta($netLine['values'][$i], $this->net(['channel_id' => $column['key']]), 0.01, $column['label']);
        }
        $this->assertEqualsWithDelta($this->net([]), $this->net(['platform' => 'Shopify']) + $this->net(['platform' => 'Amazon']), 0.01);
        // Shop A 430 + Shop B 240 (300 - 30 fees - 30 shared).
        $this->assertEquals(670, $this->net(['platform' => 'Shopify']));
    }

    public function test_filter_works_with_month_and_sku_views_and_export(): void
    {
        foreach (['month', 'sku'] as $group) {
            $this->assertEquals(430, $this->getJson("/api/finance/profit-loss?period=this_month&group={$group}&channel_id={$this->stores['Shop A']->id}")
                ->assertOk()->json('summary.net_profit.total'));
        }

        $this->get('/api/finance/profit-loss/export?period=this_month&platform=Amazon')->assertOk()
            ->assertDownload('northstar-profit-loss-all-amazon-stores-2026-07-01.csv');
        $this->getJson('/api/finance/profit-loss?channel_id=999999')->assertNotFound();
        $this->getJson('/api/finance/profit-loss?platform=Walmart')->assertUnprocessable();
    }
}
