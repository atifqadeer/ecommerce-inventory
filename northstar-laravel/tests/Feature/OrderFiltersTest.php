<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');

        $store = fn (string $platform, string $name) => $this->user->channels()->create(['platform' => $platform, 'name' => $name, 'handle' => '', 'color' => '#000', 'status' => 'connected']);
        $shopA = $store('Shopify', 'Shop A');
        $shopB = $store('Shopify', 'Shop B');
        $amazon = $store('Amazon', 'Amazon US');

        $product = fn (string $sku, string $warehouse) => $this->user->products()->create(['sku' => $sku, 'name' => $sku, 'warehouse' => $warehouse, 'price' => 10, 'on_hand' => 50]);
        $fba = $product('SALT', 'Amazon FBA');
        $main = $product('MUG', 'Main warehouse');

        $order = function (Channel $channel, string $number, array $products, string $status = 'shipped') {
            $order = $this->user->orders()->create(['channel_id' => $channel->id, 'number' => $number, 'customer_name' => 'A', 'status' => $status, 'subtotal' => 10, 'placed_at' => now()]);
            foreach ($products as $p) {
                /** @var Product $p */
                $order->items()->create(['product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'quantity' => 1, 'unit_price' => 10]);
            }
        };
        $order($shopA, 'A-1', [$main]);
        $order($shopA, 'A-2', [$main, $fba], 'paid');
        $order($shopB, 'B-1', [$main]);
        $order($amazon, 'Z-1', [$fba]);
        $order($amazon, 'Z-2', []); // known only from payments: no items, so no warehouse
    }

    private function numbers(array $query): array
    {
        return collect($this->getJson('/api/orders?'.http_build_query($query))->assertOk()->json('data'))->pluck('number')->sort()->values()->all();
    }

    public function test_filter_by_store_or_by_platform(): void
    {
        $shopA = $this->user->channels()->firstWhere('name', 'Shop A');

        $this->assertSame(['A-1', 'A-2'], $this->numbers(['channel_id' => $shopA->id]));
        $this->assertSame(['A-1', 'A-2', 'B-1'], $this->numbers(['platform' => 'Shopify']));
        $this->assertSame(['Z-1', 'Z-2'], $this->numbers(['platform' => 'Amazon']));
    }

    public function test_products_filter_by_store_platform_and_warehouse(): void
    {
        $channels = $this->user->channels()->get()->keyBy('name');
        $salt = $this->user->products()->firstWhere('sku', 'SALT');
        $mug = $this->user->products()->firstWhere('sku', 'MUG');
        $this->user->products()->create(['sku' => 'BOARD', 'name' => 'Board', 'warehouse' => 'Main warehouse', 'price' => 20]); // not listed anywhere
        $salt->listings()->create(['channel_id' => $channels['Amazon US']->id]);
        $mug->listings()->create(['channel_id' => $channels['Shop B']->id]);

        $skus = fn (array $query) => collect($this->getJson('/api/products?'.http_build_query($query))->assertOk()->json())->pluck('sku')->sort()->values()->all();

        $this->assertSame(['MUG'], $skus(['channel_id' => $channels['Shop B']->id]));
        $this->assertSame([], $skus(['channel_id' => $channels['Shop A']->id]));
        $this->assertSame(['MUG'], $skus(['platform' => 'Shopify']));
        $this->assertSame(['SALT'], $skus(['platform' => 'Amazon']));
        $this->assertSame(['BOARD', 'MUG'], $skus(['warehouse' => 'Main warehouse']));
        $this->assertSame(['MUG'], $skus(['warehouse' => 'Main warehouse', 'platform' => 'Shopify']));
    }

    public function test_warehouse_list_has_only_warehouses_in_use(): void
    {
        $this->assertSame(['Amazon FBA', 'Main warehouse'], $this->getJson('/api/meta')->json('stock_warehouses'));
    }
}
