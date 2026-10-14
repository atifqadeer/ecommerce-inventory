<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Csv;
use App\Services\OrderService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function index(Request $request): array
    {
        $filtered = $this->filtered($request);

        $statusCounts = (clone $filtered)->reorder()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        /** @var LengthAwarePaginator $page */
        $page = $filtered->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with('channel:id,name,platform,color')
            ->withSum('items as item_count', 'quantity')
            ->paginate($request->integer('per_page', 15));

        return [
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'status_counts' => ['all' => $statusCounts->sum()] + $statusCounts->all(),
        ];
    }

    public function show(Request $request, int $id): Order
    {
        return $request->user()->orders()
            ->with(['channel:id,name,platform,color', 'items', 'events' => fn ($q) => $q->orderBy('created_at')->orderBy('id')])
            ->findOrFail($id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel_id' => ['required', 'integer'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'fulfillment' => ['required', Rule::in(['FBA', 'FBM'])],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'number' => ['nullable', 'string', 'max:64'],
        ]);

        if (! empty($data['number'])) {
            $number = '#'.ltrim(trim($data['number']), '#');
            $refs = [$number, ltrim($number, '#')];
            $existing = $request->user()->orders()->where('channel_id', $data['channel_id'])
                ->where(fn ($q) => $q->whereIn('number', $refs)->orWhereIn('external_id', $refs))->first();
            if ($existing) {
                $origin = $existing->external_id ? 'imported' : 'entered manually';
                throw ValidationException::withMessages(['number' => "Order {$existing->number} already exists in this store ({$origin} on {$existing->created_at->toFormattedDateString()})."]);
            }
            $data['number'] = $number;
        }

        $order = OrderService::create($request->user(), $data);

        return response()->json($this->show($request, $order->id), 201);
    }

    public function updateStatus(Request $request, int $id): Order
    {
        $request->validate(['status' => ['required', Rule::in(Order::STATUSES)]]);
        $order = $request->user()->orders()->findOrFail($id);

        OrderService::transition($order, $request->input('status'));

        return $this->show($request, $id);
    }

    public function refund(Request $request, int $id): Order
    {
        $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);
        $order = $request->user()->orders()->findOrFail($id);

        OrderService::refund($order, (float) $request->input('amount'));

        return $this->show($request, $id);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = [['Order', 'Channel', 'Customer', 'Email', 'Placed at', 'Status', 'Fulfillment', 'Subtotal', 'Channel fees', 'Shipping', 'COGS', 'Refunds', 'Net profit']];
        $this->filtered($request)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with('channel:id,name,platform')
            ->each(function (Order $o) use (&$rows) {
                $rows[] = [$o->number, $o->channel?->name, $o->customer_name, $o->customer_email, $o->placed_at->toDateTimeString(), $o->status, $o->fulfillment, $o->subtotal, $o->channel_fees, $o->shipping_cost, $o->cogs, $o->refunds, $o->net_profit];
            });

        return Csv::download('northstar-orders.csv', Csv::fromRows($rows));
    }

    /** Orders matching every filter except status (status tabs show counts across statuses). */
    private function filtered(Request $request): Builder
    {
        return $request->user()->orders()->getQuery()
            ->when($request->filled('channel_id'), fn ($q) => $q->where('channel_id', $request->integer('channel_id')))
            // Every store on one platform, e.g. all three Shopify stores.
            ->when($request->filled('platform'), fn ($q) => $q->whereHas('channel', fn ($c) => $c->where('platform', $request->input('platform'))))
            ->when($request->filled('fulfillment'), fn ($q) => $q->where('fulfillment', $request->input('fulfillment')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('placed_at', $request->date('date')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(fn ($q) => $q->where('number', 'like', $term)->orWhere('customer_name', 'like', $term)->orWhere('customer_email', 'like', $term));
            })
            ->orderByDesc('placed_at')->orderByDesc('id');
    }
}
