<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $salt;

    private Product $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-20 12:00:00');
        $this->user = User::factory()->create();
        Ledger::setupChartOfAccounts($this->user);
        $this->actingAs($this->user, 'sanctum');
        $this->salt = $this->user->products()->create(['sku' => 'SALT', 'name' => 'Pink salt', 'price' => 12, 'unit_cost' => 3, 'on_hand' => 10]);
        $this->board = $this->user->products()->create(['sku' => 'BOARD', 'name' => 'Cutting board', 'price' => 30, 'unit_cost' => 0, 'on_hand' => 0]);
    }

    private ?int $supplierId = null;

    private function supplier(): int
    {
        return $this->supplierId ??= $this->postJson('/api/purchasing/suppliers', ['name' => 'Himalaya Traders', 'email' => 'sales@example.com', 'lead_time_days' => 21, 'payment_terms_days' => 30])
            ->assertCreated()->json('id');
    }

    /** 100 salt at 2.00 and 20 boards at 10.00 (subtotal 400), plus 40 shipping and 20 other costs. */
    private function purchaseOrder(): array
    {
        return $this->postJson('/api/purchasing/orders', [
            'supplier_id' => $this->supplier(), 'warehouse' => 'Main warehouse', 'shipping_cost' => 40, 'other_costs' => 20,
            'items' => [['product_id' => $this->salt->id, 'quantity' => 100, 'unit_cost' => 2], ['product_id' => $this->board->id, 'quantity' => 20, 'unit_cost' => 10]],
        ])->assertCreated()->json();
    }

    public function test_suppliers_are_unique_and_kept_while_they_have_orders(): void
    {
        $id = $this->supplier();
        $this->postJson('/api/purchasing/suppliers', ['name' => 'Himalaya Traders'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson("/api/purchasing/suppliers/{$id}", ['name' => 'Himalaya Traders Ltd', 'lead_time_days' => 10])->assertOk()->assertJsonPath('lead_time_days', 10);

        $this->postJson('/api/purchasing/orders', ['supplier_id' => $id, 'items' => [['product_id' => $this->salt->id, 'quantity' => 1, 'unit_cost' => 1]]])->assertCreated();
        $this->deleteJson("/api/purchasing/suppliers/{$id}")->assertUnprocessable();
    }

    public function test_a_purchase_order_is_drafted_then_ordered(): void
    {
        $po = $this->purchaseOrder();
        $this->assertEquals(['PO-1001', 'draft', 400, 460], [$po['number'], $po['status'], $po['subtotal'], $po['total']]);
        $this->assertSame(0, collect($this->getJson('/api/products')->json())->firstWhere('sku', 'SALT')['incoming']);

        $po = $this->postJson("/api/purchasing/orders/{$po['id']}/order")->assertOk()->json();
        // Expected after the supplier's 21-day lead time.
        $this->assertSame(['ordered', '2026-07-20', '2026-08-10'], [$po['status'], $po['ordered_on'], $po['expected_on']]);
        $this->assertSame(100, collect($this->getJson('/api/products')->json())->firstWhere('sku', 'SALT')['incoming']);
        $this->assertSame('PO-1002', $this->purchaseOrder()['number']);
    }

    public function test_receiving_adds_stock_at_landed_average_cost_and_books_inventory(): void
    {
        $po = $this->purchaseOrder();
        $saltLine = collect($po['items'])->firstWhere('sku', 'SALT');
        $boardLine = collect($po['items'])->firstWhere('sku', 'BOARD');

        // 60 of 100 salt arrive. Landed cost: 2.00 + 60 extra × (200/400) / 100 = 2.30.
        $po = $this->postJson("/api/purchasing/orders/{$po['id']}/receive", ['items' => [$saltLine['id'] => 60], 'received_on' => '2026-07-21'])->assertOk()->json();
        $this->assertSame(['partially_received', 60], [$po['status'], $po['units_received']]);
        $salt = $this->salt->fresh();
        // (10 × 3.00 + 60 × 2.30) / 70 = 2.40
        $this->assertSame([70, 2.4], [$salt->on_hand, $salt->unit_cost]);
        $this->assertSame('Purchase order receipt', $salt->movements()->latest('id')->first()->reason);

        $this->postJson("/api/purchasing/orders/{$po['id']}/receive", ['items' => [$saltLine['id'] => 41]])
            ->assertUnprocessable()->assertJsonValidationErrors("items.{$saltLine['id']}");

        // The rest arrives. Board landed cost: 10 + 60 × (200/400) / 20 = 11.50.
        $po = $this->postJson("/api/purchasing/orders/{$po['id']}/receive", ['items' => [$saltLine['id'] => 40, $boardLine['id'] => 20]])->assertOk()->json();
        $this->assertSame(['received', 120], [$po['status'], $po['units_received']]);
        $this->assertSame([20, 11.5], [$this->board->fresh()->on_hand, $this->board->fresh()->unit_cost]);
        $this->assertCount(2, $po['receipts']);

        // Inventory 460 (the full landed cost) against supplier payables.
        $balances = Ledger::balances($this->user, '2026-12-31');
        $this->assertEquals(460, $balances[Ledger::account($this->user, '1200')->id]);
        $this->assertEquals(460, $balances[Ledger::account($this->user, '2000')->id]);
        $this->assertSame(0, collect($this->getJson('/api/products')->json())->firstWhere('sku', 'SALT')['incoming']);
    }

    public function test_paying_the_supplier_reaches_cash_and_the_cash_flow(): void
    {
        $po = $this->purchaseOrder();
        $this->postJson("/api/purchasing/orders/{$po['id']}/payments", ['amount' => 500])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $po = $this->postJson("/api/purchasing/orders/{$po['id']}/payments", ['amount' => 200, 'paid_on' => '2026-07-20', 'method' => 'Bank transfer'])->assertOk()->json();
        $this->assertEquals([200, 260, 'partially_paid'], [$po['amount_paid'], $po['balance_due'], $po['payment_status']]);

        $lines = collect($this->getJson('/api/finance/cash-flow?period=this_month&method=direct')->json('sections.0.lines'))->pluck('total', 'key');
        $this->assertEquals(-200, $lines['suppliers']);
        $this->assertNull($lines['inventory'] ?? null); // a PO receipt isn't also counted as stock bought
        $this->getJson('/api/finance/balance-sheet')->assertJsonPath('balanced', true);

        $summary = $this->getJson('/api/purchasing/orders')->json('summary');
        $this->assertEquals(260, $summary['owed_to_suppliers']);

        $po = $this->deleteJson("/api/purchasing/orders/{$po['id']}/payments/{$po['payments'][0]['id']}")->assertOk()->json();
        $this->assertSame('unpaid', $po['payment_status']);
        $this->assertSame(0, $this->user->journalEntries()->count());
    }

    public function test_buying_reduces_cash_in_hand_only_when_paid(): void
    {
        $po = $this->purchaseOrder(); // 460 in total
        $this->postJson("/api/purchasing/orders/{$po['id']}/receive", ['items' => collect($po['items'])->mapWithKeys(fn ($i) => [$i['id'] => $i['quantity']])->all()])->assertOk();

        // Received but not paid: stock and supplier payables, no cash yet.
        $cf = $this->getJson('/api/finance/cash-flow?period=this_month')->json();
        $this->assertEquals(0, $cf['cash_in_hand']['closing']);
        $this->assertEquals(460, $cf['cash_in_hand']['owed_to_suppliers']);

        $this->postJson("/api/purchasing/orders/{$po['id']}/payments", ['amount' => 200])->assertOk();

        $indirect = $this->getJson('/api/finance/cash-flow?period=this_month')->json();
        $lines = collect($indirect['sections'][0]['lines'])->keyBy('key');
        // Stock went up 460 (all bought, none sold); 260 of it is still owed; so 200 of cash left.
        $this->assertEquals(-460, $lines['inventory_change']['total']);
        $this->assertEquals(260, $lines['payables_change']['total']);
        $this->assertSame(['Stock received (to be paid)', 'Paid to suppliers'], array_column($lines['payables_change']['children'], 'label'));
        $this->assertEquals(-200, $indirect['sections'][0]['total']);

        $direct = $this->getJson('/api/finance/cash-flow?period=this_month&method=direct')->json();
        $supplierLine = collect($direct['sections'][0]['lines'])->firstWhere('key', 'suppliers');
        $this->assertSame(['Paid to suppliers (buying stock)', -200.0], [$supplierLine['label'], (float) $supplierLine['total']]);
        $this->assertEquals($direct['sections'][0]['total'], $indirect['sections'][0]['total']);

        $cash = $indirect['cash_in_hand'];
        $this->assertSame(['suppliers'], array_column($cash['rows'], 'key'));
        $this->assertEquals([-200, -200, 260], [$cash['rows'][0]['total'], $cash['closing'], $cash['owed_to_suppliers']]);
    }

    public function test_deliveries_add_up_to_the_order_total_to_the_cent(): void
    {
        // 3 units at 15 plus 10 of costs: each unit lands at 18.3333…
        foreach ([[3], [1, 2]] as $deliveries) {
            $po = $this->postJson('/api/purchasing/orders', ['supplier_id' => $this->supplier(), 'shipping_cost' => 5, 'other_costs' => 5,
                'items' => [['product_id' => $this->board->id, 'quantity' => 3, 'unit_cost' => 15]]])->assertCreated()->json();
            foreach ($deliveries as $quantity) {
                $po = $this->postJson("/api/purchasing/orders/{$po['id']}/receive", ['items' => [$po['items'][0]['id'] => $quantity]])->assertOk()->json();
            }
            $this->assertEquals(55, collect($po['receipts'])->sum('value'), json_encode($deliveries));
        }
        $this->assertEquals(110, Ledger::balances($this->user, '2026-12-31')[Ledger::account($this->user, '1200')->id]);
    }

    public function test_editing_cancelling_and_deleting_follow_what_has_happened(): void
    {
        $po = $this->purchaseOrder();
        $saltLine = collect($po['items'])->firstWhere('sku', 'SALT');
        $this->postJson("/api/purchasing/orders/{$po['id']}/receive", ['items' => [$saltLine['id'] => 30]])->assertOk();

        $edit = ['supplier_id' => $po['supplier_id'], 'items' => [['product_id' => $this->salt->id, 'quantity' => 20, 'unit_cost' => 2]]];
        $this->putJson("/api/purchasing/orders/{$po['id']}", $edit)->assertUnprocessable(); // 30 already arrived
        $edit['items'][0]['quantity'] = 30;
        $this->putJson("/api/purchasing/orders/{$po['id']}", $edit)->assertOk()->assertJsonPath('status', 'received'); // board line dropped, salt complete

        $this->postJson("/api/purchasing/orders/{$po['id']}/cancel")->assertUnprocessable();

        $draft = $this->purchaseOrder();
        $this->postJson("/api/purchasing/orders/{$draft['id']}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->deleteJson("/api/purchasing/orders/{$draft['id']}")->assertUnprocessable();
        $this->deleteJson("/api/purchasing/orders/{$this->purchaseOrder()['id']}")->assertOk();
    }
}
