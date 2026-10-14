<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifySyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** What the fake store currently reports. */
    private int $onHand = 7;

    private string $fulfillment = 'UNFULFILLED';

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    private function fakeShopify(): void
    {
        Http::fake(['test-store.myshopify.com/admin/api/*' => function (Request $request) {
            $query = $request['query'];
            $page = ['hasNextPage' => false, 'endCursor' => null];

            if (str_contains($query, 'shop {')) {
                return Http::response(['data' => ['shop' => ['name' => 'Test', 'currencyCode' => 'CAD', 'myshopifyDomain' => 'test-store.myshopify.com']]]);
            }
            if (str_contains($query, 'productVariants')) {
                return Http::response(['data' => ['productVariants' => ['pageInfo' => $page, 'nodes' => [
                    ['legacyResourceId' => '111', 'sku' => 'TEE-M', 'title' => 'M', 'price' => '25.00', 'inventoryQuantity' => $this->onHand,
                        'product' => ['title' => 'Tee', 'status' => 'ACTIVE'], 'inventoryItem' => ['unitCost' => ['amount' => '8.00']]],
                    ['legacyResourceId' => '222', 'sku' => '', 'title' => 'Default Title', 'price' => '40.00', 'inventoryQuantity' => -2,
                        'product' => ['title' => 'Mug', 'status' => 'DRAFT'], 'inventoryItem' => ['unitCost' => null]],
                    ['legacyResourceId' => '333', 'sku' => 'OLD', 'title' => 'Default Title', 'price' => '5.00', 'inventoryQuantity' => 3,
                        'product' => ['title' => 'Archived', 'status' => 'ARCHIVED'], 'inventoryItem' => ['unitCost' => null]],
                ]]]]);
            }

            return Http::response(['data' => ['orders' => ['pageInfo' => $page, 'nodes' => [[
                'legacyResourceId' => '9001', 'name' => '#1001', 'createdAt' => now()->subDay()->toIso8601String(), 'cancelledAt' => null,
                'displayFulfillmentStatus' => $this->fulfillment, 'email' => 'ana@example.com',
                'customer' => ['displayName' => 'Ana Lee', 'email' => 'ana@example.com'],
                'shippingAddress' => ['name' => 'Ana Lee', 'address1' => '1 Main St', 'address2' => null, 'city' => 'Toronto', 'provinceCode' => 'ON', 'zip' => 'M5V', 'country' => 'Canada'],
                'subtotalPriceSet' => ['shopMoney' => ['amount' => '50.00']],
                'totalRefundedSet' => ['shopMoney' => ['amount' => '0.00']],
                'fulfillments' => [],
                'lineItems' => ['nodes' => [['sku' => 'TEE-M', 'name' => 'Tee - M', 'quantity' => 2, 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '25.00']], 'variant' => ['legacyResourceId' => '111']]]],
            ]]]]]);
        }]);
    }

    public function test_connecting_shopify_imports_products_and_orders(): void
    {
        $this->fakeShopify();

        $response = $this->postJson('/api/channels', [
            'platform' => 'Shopify', 'name' => 'Purest Peaks', 'connection' => 'api', 'handle' => 'https://Test-Store.myshopify.com/admin', 'auth_type' => 'access_token', 'access_token' => 'shpat_test',
        ])->assertCreated()->assertJsonPath('channel.handle', 'test-store.myshopify.com')->assertJsonPath('channel.currency', 'CAD');
        $this->assertArrayNotHasKey('credentials', $response->json('channel'));
        $this->assertSame(['success', 'success'], array_column($response->json('logs'), 'status'));

        $products = $this->user->products()->orderBy('sku')->get();
        $this->assertSame(['SHOPIFY-222', 'TEE-M'], $products->pluck('sku')->all());
        $this->assertSame('Tee / M', $products[1]->name);
        $this->assertSame(7, $products[1]->on_hand);
        $this->assertSame(0, $products[0]->on_hand);

        $order = $this->user->orders()->with('items')->sole();
        $this->assertSame(['#1001', 'paid', 'Ana Lee', 50.0, 16.0], [$order->number, $order->status, $order->customer_name, $order->subtotal, $order->cogs]);
        $this->assertSame($products[1]->id, $order->items->sole()->product_id);

        $this->getJson('/api/meta')->assertJsonPath('currency', 'CAD');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('stats.0.value', 50);
    }

    public function test_resync_updates_stock_and_orders_without_duplicates(): void
    {
        $this->fakeShopify();
        $channelId = $this->postJson('/api/channels', ['platform' => 'Shopify', 'name' => 'Purest Peaks', 'connection' => 'api', 'handle' => 'test-store', 'auth_type' => 'access_token', 'access_token' => 'shpat_test'])->json('channel.id');

        [$this->onHand, $this->fulfillment] = [4, 'FULFILLED'];
        $this->postJson("/api/channels/{$channelId}/sync")->assertOk();

        $this->assertSame(1, $this->user->orders()->count());
        $this->assertSame('shipped', $this->user->orders()->sole()->status);
        $tee = $this->user->products()->firstWhere('sku', 'TEE-M');
        $this->assertSame(4, $tee->on_hand);
        $this->assertSame(-3, $tee->movements()->latest('id')->first()->quantity);
    }

    public function test_bad_credentials_are_rejected_without_creating_a_channel(): void
    {
        Http::fake(['*' => Http::response(['errors' => '[API] Invalid API key or access token'], 401)]);

        $this->postJson('/api/channels', ['platform' => 'Shopify', 'name' => 'Purest Peaks', 'connection' => 'api', 'handle' => 'test-store.myshopify.com', 'auth_type' => 'access_token', 'access_token' => 'bad'])
            ->assertUnprocessable()->assertJsonValidationErrors('access_token');
        $this->assertSame(0, $this->user->channels()->count());
    }

    public function test_platforms_without_an_api_cannot_use_api_connection(): void
    {
        $this->postJson('/api/channels', ['platform' => 'Amazon', 'name' => 'Amazon US', 'connection' => 'api', 'handle' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('connection');
    }
}
