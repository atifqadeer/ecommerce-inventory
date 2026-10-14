<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DemoWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        DemoWorkspace::seed($this->user);
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_dashboard_and_finance_endpoints_respond(): void
    {
        $this->getJson('/api/dashboard')->assertOk()->assertJsonCount(6, 'stats');
        $this->getJson('/api/finance/balance-sheet')->assertOk()->assertJsonPath('balanced', true);

        foreach (['channel', 'month', 'sku'] as $group) {
            $statement = $this->getJson("/api/finance/profit-loss?period=last_6_months&group={$group}")->assertOk()->json();
            // Net profit is the same whichever way the statement is sliced.
            $this->assertEqualsWithDelta(array_sum($statement['lines'][4]['values']), $statement['lines'][4]['total'], 1);
        }
    }

    public function test_order_lifecycle_reserves_and_consumes_stock(): void
    {
        $channel = $this->user->channels()->firstWhere('name', 'Shopify');
        $product = $this->user->products()->create(['sku' => 'T-1', 'name' => 'Test', 'warehouse' => 'East Coast', 'price' => 10, 'unit_cost' => 4, 'on_hand' => 10]);

        $this->postJson('/api/orders', ['channel_id' => $channel->id, 'customer_name' => 'Ann', 'fulfillment' => 'FBM', 'items' => [['product_id' => $product->id, 'quantity' => 11]]])
            ->assertUnprocessable();

        $order = $this->postJson('/api/orders', ['channel_id' => $channel->id, 'customer_name' => 'Ann', 'fulfillment' => 'FBM', 'items' => [['product_id' => $product->id, 'quantity' => 3]]])
            ->assertCreated()->assertJsonPath('subtotal', 30)->json();
        $this->assertSame(3, $product->fresh()->reserved);

        $this->patchJson("/api/orders/{$order['id']}/status", ['status' => 'delivered'])->assertUnprocessable();
        $this->patchJson("/api/orders/{$order['id']}/status", ['status' => 'shipped'])->assertOk();

        $product->refresh();
        $this->assertSame(0, $product->reserved);
        $this->assertSame(7, $product->on_hand);
    }

    public function test_cancelling_releases_reserved_stock(): void
    {
        $channel = $this->user->channels()->first();
        $product = $this->user->products()->create(['sku' => 'T-2', 'name' => 'Test', 'warehouse' => 'East Coast', 'price' => 10, 'unit_cost' => 4, 'on_hand' => 5]);
        $order = $this->postJson('/api/orders', ['channel_id' => $channel->id, 'customer_name' => 'Bo', 'fulfillment' => 'FBM', 'items' => [['product_id' => $product->id, 'quantity' => 2]]])->json();

        $this->patchJson("/api/orders/{$order['id']}/status", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(0, $product->fresh()->reserved);
        $this->assertSame(5, $product->fresh()->on_hand);
    }

    public function test_journal_entries_must_balance(): void
    {
        $cash = $this->user->ledgerAccounts()->firstWhere('code', '1000');
        $equity = $this->user->ledgerAccounts()->firstWhere('code', '3000');

        $this->postJson('/api/finance/journal-entries', ['date' => now()->toDateString(), 'description' => 'Bad', 'lines' => [
            ['ledger_account_id' => $cash->id, 'debit' => 100], ['ledger_account_id' => $equity->id, 'credit' => 90],
        ]])->assertUnprocessable();

        $this->postJson('/api/finance/journal-entries', ['date' => now()->toDateString(), 'description' => 'Capital', 'lines' => [
            ['ledger_account_id' => $cash->id, 'debit' => 100], ['ledger_account_id' => $equity->id, 'credit' => 100],
        ]])->assertCreated();

        $this->getJson('/api/finance/balance-sheet')->assertJsonPath('balanced', true);
    }

    public function test_users_cannot_see_each_others_data(): void
    {
        $other = User::factory()->create();
        DemoWorkspace::seed($other);
        $order = $other->orders()->first();
        $product = $other->products()->first();

        $this->getJson("/api/orders/{$order->id}")->assertNotFound();
        $this->getJson("/api/products/{$product->id}")->assertNotFound();
        $this->deleteJson("/api/products/{$product->id}")->assertNotFound();
        $this->assertSame($this->user->orders()->count(), $this->getJson('/api/orders')->json('meta.total'));
    }
}
