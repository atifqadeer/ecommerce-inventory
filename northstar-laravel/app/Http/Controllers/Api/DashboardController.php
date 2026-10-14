<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\Payout;
use App\Models\PurchaseOrder;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ProfitLoss;
use App\Services\Statements\StatementImporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): array
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $current = $this->metrics($user, $now->subDays(30), $now);
        $previous = $this->metrics($user, $now->subDays(60), $now->subDays(30));

        $change = fn (string $key) => $previous[$key] == 0 ? null : round(($current[$key] - $previous[$key]) / abs($previous[$key]) * 100, 1);
        $inventoryValue = (float) $user->products()->selectRaw('SUM(on_hand * unit_cost) as value')->value('value');

        $channelRevenue = $user->channels()->orderBy('id')->get()->map(fn (Channel $channel) => [
            'id' => $channel->id,
            'platform' => $channel->platform,
            'name' => $channel->name,
            'color' => $channel->color,
            'status' => $channel->status,
            'revenue' => round((float) $channel->orders()->where('status', '!=', 'cancelled')->where('placed_at', '>=', $now->subDays(30))->sum('subtotal'), 2),
        ]);
        $maxRevenue = max(1, $channelRevenue->max('revenue'));

        $topProducts = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.user_id', $user->id)
            ->where('orders.status', '!=', 'cancelled')
            ->where('orders.placed_at', '>=', $now->subDays(30))
            ->groupBy('order_items.sku', 'order_items.name')
            ->selectRaw('order_items.sku, order_items.name, SUM(quantity) as units, SUM(quantity * unit_price) as revenue, SUM(quantity * (unit_price - unit_cost)) as profit')
            ->orderByDesc('units')
            ->limit(5)
            ->get();

        return [
            'stats' => [
                ['key' => 'revenue', 'label' => 'Revenue', 'value' => $current['revenue'], 'format' => 'money', 'previous' => $previous['revenue'], 'change' => $change('revenue')],
                ['key' => 'orders', 'label' => 'Orders', 'value' => $current['orders'], 'format' => 'number', 'previous' => $previous['orders'], 'change' => $change('orders')],
                ['key' => 'units', 'label' => 'Units sold', 'value' => $current['units'], 'format' => 'number', 'previous' => $previous['units'], 'change' => $change('units')],
                ['key' => 'gross_profit', 'label' => 'Gross profit', 'value' => $current['gross_profit'], 'format' => 'money', 'previous' => $previous['gross_profit'], 'change' => $change('gross_profit')],
                ['key' => 'net_profit', 'label' => 'Net profit', 'value' => $current['net_profit'], 'format' => 'money', 'previous' => $previous['net_profit'], 'change' => $change('net_profit')],
                ['key' => 'inventory_value', 'label' => 'Inventory value', 'value' => round($inventoryValue, 2), 'format' => 'money', 'previous' => null, 'change' => null],
            ],
            'recent_orders' => $user->orders()->with('channel:id,name,platform,color')->latest('placed_at')->limit(5)->get(),
            'channels' => $channelRevenue->map(fn ($c) => $c + ['share' => round($c['revenue'] / $maxRevenue * 100)]),
            'low_stock' => $user->products()->get()->filter->is_low_stock->sortBy('available')->take(5)->values(),
            'top_products' => $topProducts,
            'all_synced' => ! $user->channels()->where('status', '!=', 'connected')->exists(),
        ];
    }

    /** Sidebar badges. */
    public function counts(Request $request): array
    {
        $user = $request->user();

        return [
            'open_orders' => $user->orders()->whereIn('status', Order::OPEN_STATUSES)->count(),
            'low_stock' => $user->products()->get()->filter->is_low_stock->count(),
            'channel_issues' => $user->channels()->where('status', 'action_needed')->count(),
        ];
    }

    /** Option lists for dropdowns. */
    public function meta(Request $request): array
    {
        return [
            'channels' => $request->user()->channels()->orderBy('platform')->orderBy('id')->get(['id', 'platform', 'name', 'handle', 'color', 'currency', 'status', 'credentials']),
            'supported_channels' => collect(Channel::SUPPORTED)->map(fn ($color, $name) => compact('name', 'color') + ['api' => in_array($name, Channel::INTEGRATED, true)])->values(),
            'payment_types' => collect(PaymentTransaction::TYPES)->map(fn ($t, $value) => ['value' => $value, 'label' => $t[0], 'sign' => $t[1]])->values(),
            'payout_statuses' => Payout::STATUSES,
            'purchase_statuses' => PurchaseOrder::STATUSES,
            'statement_formats' =>collect(StatementImporter::formats())->map(fn ($f) => [
                'key' => $f->key(), 'label' => $f->label(), 'platform' => $f->platform(), 'hint' => $f->hint(),
            ])->values(),
            'currency' => $request->user()->channels()->whereNotNull('currency')->orderBy('id')->value('currency') ?? 'USD',
            'sync_frequencies' => Channel::FREQUENCIES,
            'warehouses' => Product::warehousesFor($request->user()),
            // Only warehouses that products actually use, for filters.
            'stock_warehouses' => $request->user()->products()->distinct()->orderBy('warehouse')->pluck('warehouse'),
            'adjustment_reasons' => StockMovement::REASONS,
            'order_statuses' => Order::STATUSES,
            'order_transitions' => Order::TRANSITIONS,
            'expense_categories' => collect(Expense::CATEGORIES)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'periods' => collect(ProfitLoss::PERIODS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'years' => $this->years($request->user()),
        ];
    }

    /** Calendar years the workspace has data for, newest first (always including this year). */
    private function years(User $user): array
    {
        $earliest = collect([
            $user->orders()->min('placed_at'),
            $user->expenses()->min('incurred_on'),
            $user->paymentTransactions()->min('occurred_at'),
            $user->payouts()->min('paid_on'),
        ])->filter()->map(fn ($date) => (int) substr((string) $date, 0, 4))->min();
        $current = (int) now()->format('Y');

        return range($current, min($earliest ?? $current, $current));
    }

    private function metrics(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = $user->orders()->where('status', '!=', 'cancelled')->where('placed_at', '>=', $from)->where('placed_at', '<', $to);
        $totals = (clone $orders)->selectRaw('COUNT(*) as orders, SUM(subtotal) as revenue, SUM(cogs) as cogs, SUM(channel_fees + shipping_cost + refunds) as costs')->first();
        $units = OrderItem::whereIn('order_id', (clone $orders)->select('id'))->sum('quantity');
        $expenses = $user->expenses()->whereDate('incurred_on', '>=', $from->toDateString())->whereDate('incurred_on', '<', $to->toDateString())->sum('amount');

        $revenue = (float) $totals->revenue;
        $gross = $revenue - (float) $totals->cogs;

        return [
            'revenue' => round($revenue, 2),
            'orders' => (int) $totals->orders,
            'units' => (int) $units,
            'gross_profit' => round($gross, 2),
            'net_profit' => round($gross - (float) $totals->costs - (float) $expenses, 2),
        ];
    }
}
