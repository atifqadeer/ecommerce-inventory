<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Csv;
use App\Services\Purchasing;
use App\Services\Statements\StockImporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = $this->filtered($request)->with('listings.channel:id,name,platform,color')->orderBy('sku')->get();
        // Units on open purchase orders that haven't arrived yet.
        $incoming = Purchasing::incoming($request->user());
        $products->each(fn ($p) => $p->setAttribute('incoming', $incoming[$p->id] ?? 0));

        // "Low stock" depends on computed availability, so filter after loading.
        return $request->boolean('low_stock') ? $products->filter->is_low_stock->values() : $products;
    }

    public function show(Request $request, int $id): Product
    {
        return $request->user()->products()
            ->with(['listings.channel:id,name,platform,color', 'movements' => fn ($q) => $q->latest()->latest('id')->limit(25)])
            ->findOrFail($id);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $product = DB::transaction(function () use ($request, $data) {
            $product = $request->user()->products()->create($data);
            if ($product->on_hand > 0) {
                $product->movements()->create(['quantity' => $product->on_hand, 'reason' => 'Opening balance', 'note' => 'Product created']);
            }
            $this->syncListings($request, $product, $data['listings'] ?? []);

            return $product;
        });

        return response()->json($this->show($request, $product->id), 201);
    }

    public function update(Request $request, int $id): Product
    {
        $product = $request->user()->products()->findOrFail($id);
        $data = $this->validated($request, $product);
        // Stock levels only change through adjustments so every change is in the movement history.
        unset($data['on_hand']);

        DB::transaction(function () use ($request, $product, $data) {
            $product->update($data);
            if (array_key_exists('listings', $data)) {
                $this->syncListings($request, $product, $data['listings']);
            }
        });

        return $this->show($request, $id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $product = $request->user()->products()->findOrFail($id);
        if ($product->reserved > 0) {
            return response()->json(['message' => "{$product->sku} has {$product->reserved} units reserved for open orders and can't be deleted yet."], 422);
        }
        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    /** Bulk stock adjustment for one or more products. */
    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
            'reason' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $products = $request->user()->products()->whereIn('id', $data['product_ids'])->get();
        DB::transaction(fn () => $products->each->adjustStock($data['quantity'], $data['reason'], $data['note'] ?? null));

        return response()->json(['message' => "Adjusted {$products->count()} product(s).", 'products' => $products->fresh()]);
    }

    /** Upload a stock count / product list: creates missing SKUs and sets on-hand stock to the counted quantity. */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'extensions:csv,tsv,txt', 'max:20480']]);

        return response()->json(StockImporter::import($request->user(), $data['file']));
    }

    public function importTemplate(): StreamedResponse
    {
        return Csv::download('northstar-stock-template.csv', Csv::fromRows([
            StockImporter::HEADERS,
            ['TEE-BLK-M', 'Everyday tee / black / M', 120, 8.50, 24.00, 'Main warehouse', 20],
        ]));
    }

    public function export(Request $request): StreamedResponse
    {
        $channels = $request->user()->channels()->orderBy('id')->get();
        $rows = [array_merge(['SKU', 'Product', 'Warehouse', 'Price', 'Unit cost', 'Total', 'Available', 'Reserved'], $channels->pluck('name')->all())];

        foreach ($this->filtered($request)->with('listings')->orderBy('sku')->get() as $p) {
            $allocations = $channels->map(fn ($c) => $p->listings->firstWhere('channel_id', $c->id)?->allocated ?? 0)->all();
            $rows[] = array_merge([$p->sku, $p->name, $p->warehouse, $p->price, $p->unit_cost, $p->on_hand, $p->available, $p->reserved], $allocations);
        }

        return Csv::download('northstar-inventory.csv', Csv::fromRows($rows));
    }

    private function filtered(Request $request): Builder
    {
        return $request->user()->products()->getQuery()
            ->when($request->filled('warehouse'), fn ($q) => $q->where('warehouse', $request->input('warehouse')))
            ->when($request->filled('channel_id'), fn ($q) => $q->whereHas('listings', fn ($q) => $q->where('channel_id', $request->integer('channel_id'))))
            // Listed on any store of a platform, e.g. any Shopify store.
            ->when($request->filled('platform'), fn ($q) => $q->whereHas('listings.channel', fn ($q) => $q->where('platform', $request->input('platform'))))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(fn ($q) => $q->where('sku', 'like', $term)->orWhere('name', 'like', $term));
            });
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'sku' => ['required', 'string', 'max:64', Rule::unique('products')->where('user_id', $request->user()->id)->ignore($product?->id)],
            'name' => ['required', 'string', 'max:255'],
            'warehouse' => ['required', Rule::in(Product::warehousesFor($request->user()))],
            'price' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'reorder_point' => ['required', 'integer', 'min:0'],
            'on_hand' => [$product ? 'sometimes' : 'required', 'integer', 'min:0'],
            'listings' => ['sometimes', 'array'],
            'listings.*.channel_id' => ['required', 'integer', 'distinct'],
            'listings.*.external_id' => ['nullable', 'string', 'max:255'],
            'listings.*.allocated' => ['required', 'integer', 'min:0'],
        ]);
    }

    private function syncListings(Request $request, Product $product, array $listings): void
    {
        $channelIds = $request->user()->channels()->pluck('id');
        $keep = [];
        foreach ($listings as $listing) {
            if (! $channelIds->contains($listing['channel_id'])) {
                continue;
            }
            $product->listings()->updateOrCreate(['channel_id' => $listing['channel_id']], [
                'external_id' => $listing['external_id'] ?? null,
                'allocated' => $listing['allocated'],
            ]);
            $keep[] = $listing['channel_id'];
        }
        $product->listings()->whereNotIn('channel_id', $keep)->delete();
    }
}
