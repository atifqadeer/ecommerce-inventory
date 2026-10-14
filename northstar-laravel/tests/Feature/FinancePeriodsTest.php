<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Finance periods: presets, a calendar year, or a custom range of months. */
class FinancePeriodsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-20 12:00:00');
        $this->user = User::factory()->create();
        Ledger::setupChartOfAccounts($this->user);
        $this->actingAs($this->user, 'sanctum');

        $channel = $this->user->channels()->create(['platform' => 'Shopify', 'name' => 'Store', 'handle' => '', 'color' => '#000', 'status' => 'connected']);
        foreach (['2024-11-05' => 100, '2025-03-10' => 200, '2025-12-31' => 300, '2026-02-01' => 400] as $date => $subtotal) {
            $this->user->orders()->create(['channel_id' => $channel->id, 'number' => "#{$date}", 'customer_name' => 'A', 'status' => 'delivered',
                'subtotal' => $subtotal, 'placed_at' => "{$date} 12:00:00"]);
        }
    }

    public function test_a_calendar_year_is_compared_with_the_year_before(): void
    {
        $pl = $this->getJson('/api/finance/profit-loss?period=year:2025&group=month')->assertOk()->json();

        $this->assertSame(['2025', '2025-01-01', '2025-12-31', '2024-01-01', '2024-12-31'],
            [$pl['period']['label'], $pl['period']['from'], $pl['period']['to'], $pl['period']['previous_from'], $pl['period']['previous_to']]);
        $this->assertCount(12, $pl['columns']);
        $this->assertEquals(500, $pl['summary']['revenue']['total']);
        $this->assertEquals(100, $pl['summary']['revenue']['previous']);
    }

    public function test_a_custom_range_spans_whole_months_across_years(): void
    {
        // Given in either order.
        $pl = $this->getJson('/api/finance/profit-loss?period=custom:2026-02:2025-12&group=month')->assertOk()->json();

        $this->assertSame(['Dec 2025 – Feb 2026', '2025-12-01', '2026-02-28', '2025-09-01', '2025-11-30'],
            [$pl['period']['label'], $pl['period']['from'], $pl['period']['to'], $pl['period']['previous_from'], $pl['period']['previous_to']]);
        $this->assertSame(['Dec 2025', 'Jan 2026', 'Feb 2026'], array_column($pl['columns'], 'label'));
        $this->assertEquals(700, $pl['summary']['revenue']['total']);

        $cf = $this->getJson('/api/finance/cash-flow?period=custom:2025-01:2026-06&method=direct')->assertOk()->json();
        $this->assertSame(['Jan 2025 – Jun 2026', 18], [$cf['period']['label'], count($cf['columns'])]);
        $this->assertEquals(100, $cf['summary']['opening']);   // the 2024 order is before the range
        $this->assertEquals(900, $cf['summary']['net']);

        $this->get('/api/finance/profit-loss/export?period=year:2025')->assertOk();
    }

    public function test_invalid_periods_are_rejected(): void
    {
        foreach (['year:abc', 'year:1850', 'custom:2025-13:2026-01', 'custom:2010-01:2026-01', 'next_decade'] as $period) {
            $this->getJson('/api/finance/profit-loss?period='.urlencode($period))->assertUnprocessable()->assertJsonValidationErrors('period');
        }
    }

    public function test_the_year_list_covers_the_data(): void
    {
        $this->assertSame([2026, 2025, 2024], $this->getJson('/api/meta')->json('years'));
        $this->assertContains('last_12_months', array_column($this->getJson('/api/meta')->json('periods'), 'value'));
    }
}
