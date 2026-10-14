<?php

namespace App\Services\Statements;

use App\Models\Channel;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Payout;
use App\Models\Product;
use App\Models\StatementImport;
use App\Services\OrderService;
use App\Services\PaymentLedger;
use App\Services\Statements\Formats\AmazonOrdersReport;
use App\Services\Statements\Formats\AmazonTransactionReport;
use App\Services\Statements\Formats\AmazonTransactionView;
use App\Services\Statements\Formats\EbayOrdersReport;
use App\Services\Statements\Formats\EbayTransactionReport;
use App\Services\Statements\Formats\EtsySoldOrderItems;
use App\Services\Statements\Formats\GenericTemplate;
use App\Services\Statements\Formats\ShopifyOrdersExport;
use App\Services\Statements\Formats\ShopifyPaymentTransactions;
use App\Services\Statements\Formats\ShopifyPayouts;
use App\Services\Statements\Formats\StatementFormat;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports a marketplace statement into one store: upserts its orders (matched by order ID, so overlapping
 * or repeated reports never double count), records fees and other charges as expenses, links lines to
 * products by SKU (or exact name) and takes sold units off on-hand stock.
 *
 * Stock is only touched for stores without an API connection; an API-connected store reports its own stock levels.
 */
class StatementImporter
{
    /** @return array<string, StatementFormat> */
    public static function formats(): array
    {
        $formats = [
            new ShopifyOrdersExport,
            new ShopifyPaymentTransactions,
            new ShopifyPayouts,
            new AmazonTransactionReport,
            new AmazonTransactionView,
            new AmazonOrdersReport,
            new EtsySoldOrderItems,
            new EbayTransactionReport,
            new EbayOrdersReport,
            new GenericTemplate,
        ];

        return collect($formats)->keyBy(fn (StatementFormat $f) => $f->key())->all();
    }

    public static function import(Channel $channel, UploadedFile $file, ?string $formatKey = null): StatementImport
    {
        @set_time_limit(0);
        $content = $file->get();
        $hash = sha1($content);

        if ($previous = $channel->statementImports()->where('hash', $hash)->first()) {
            throw ValidationException::withMessages(['file' => "This file was already uploaded to {$channel->name} on {$previous->created_at->toFormattedDateString()}."]);
        }

        $rows = StatementReader::rows($content);
        [$format, $headerRow] = self::detect($channel, $rows, $formatKey);
        $parsed = $format->parse(StatementReader::records($rows, $headerRow));

        if (! $parsed['orders'] && ! $parsed['expenses'] && ! $parsed['transactions'] && ! $parsed['payouts']) {
            throw ValidationException::withMessages(['file' => 'No orders, transactions or payouts were found in this file.']);
        }

        return (new self($channel, $format, $parsed))->save($file, $hash, count($rows) - $headerRow - 1);
    }

    /** Delete an upload: remove the orders and charges it last wrote, and put their units back in stock. */
    public static function undo(StatementImport $import): void
    {
        DB::transaction(function () use ($import) {
            PaymentLedger::detachImport($import);
            $returned = [];
            foreach ($import->orders()->with('items')->get() as $order) {
                if ($order->stock_deducted) {
                    foreach ($order->items as $item) {
                        if ($item->product_id) {
                            $returned[$item->product_id] = ($returned[$item->product_id] ?? 0) + $item->quantity;
                        }
                    }
                }
                $order->delete();
            }
            foreach ($returned as $productId => $quantity) {
                Product::find($productId)?->adjustStock($quantity, 'Statement removed', $import->filename);
            }
            $import->expenseRecords()->delete();
            $import->delete();
        });
    }

    /** @return array{0: StatementFormat, 1: int} */
    private static function detect(Channel $channel, array $rows, ?string $formatKey): array
    {
        $formats = self::formats();
        $candidates = $formatKey ? array_intersect_key($formats, [$formatKey => true]) : $formats;

        foreach ($candidates as $format) {
            $headerRow = StatementReader::headerRow($rows, $format->requiredHeaders());
            if ($headerRow === null) {
                continue;
            }
            if ($format->platform() && $format->platform() !== $channel->platform) {
                throw ValidationException::withMessages(['file' => "This looks like a {$format->label()}, but {$channel->name} is a {$channel->platform} store. Upload it to one of your {$format->platform()} stores."]);
            }

            return [$format, $headerRow];
        }

        $expected = $formatKey
            ? 'It needs the columns: '.collect($formats[$formatKey]->requiredHeaders())->map(fn ($h) => implode(' / ', (array) $h))->implode(', ').'.'
            : 'Upload one of the supported reports, or fill in the Northstar template.';

        throw ValidationException::withMessages(['file' => "This file's columns weren't recognised. {$expected}"]);
    }

    /** product id => change in on-hand units */
    private array $stockChanges = [];

    private array $warnings;

    private int $productsCreated = 0;

    /** @var array<string, Product> lower-cased SKU / name → product */
    private array $bySku = [];

    private array $byName = [];

    private function __construct(private Channel $channel, private StatementFormat $format, private array $parsed)
    {
        $this->warnings = $parsed['warnings'];
        foreach ($channel->user->products as $product) {
            $this->bySku[mb_strtolower($product->sku)] = $product;
            $this->byName[mb_strtolower($product->name)] ??= $product;
        }
    }

    private function save(UploadedFile $file, string $hash, int $rowCount): StatementImport
    {
        $import = DB::transaction(function () use ($file, $hash, $rowCount) {
            $import = $this->channel->statementImports()->create([
                'user_id' => $this->channel->user_id,
                'format' => $this->format->key(),
                'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                'hash' => $hash,
                'rows' => max(0, $rowCount),
            ]);

            $created = $updated = $units = 0;
            $skippedLines = 0;
            foreach ($this->parsed['orders'] as $data) {
                if (! $data['placed_at']) {
                    $this->warnings[] = "Order {$data['number']} has no readable date and was skipped.";

                    continue;
                }
                [$isNew, $orderUnits, $unmatched] = $this->upsertOrder($import, $data);
                $isNew ? $created++ : $updated++;
                $units += $orderUnits;
                $skippedLines += $unmatched;
            }

            foreach ($this->parsed['expenses'] as $expense) {
                $attributes = ['statement_import_id' => $import->id, 'category' => $expense['category'], 'description' => $expense['description'],
                    'amount' => round($expense['amount'], 2), 'incurred_on' => $expense['date']->toDateString()];
                $existing = $this->channel->user->expenses()->where('channel_id', $this->channel->id)->where('external_ref', $expense['ref'])->first()
                    // The same cost typed in by hand: adopt it instead of adding a copy.
                    ?? $this->channel->user->expenses()->where('channel_id', $this->channel->id)->whereNull('external_ref')
                        ->where('category', $attributes['category'])->where('amount', $attributes['amount'])
                        ->whereDate('incurred_on', $attributes['incurred_on'])->whereDoesntHave('paymentTransaction')->first();

                $existing
                    ? $existing->update($attributes + ['external_ref' => $expense['ref']])
                    : $this->channel->user->expenses()->create($attributes + ['channel_id' => $this->channel->id, 'external_ref' => $expense['ref']]);
            }

            [$transactions, $payouts, $matched] = $this->savePayments($import);

            $this->applyStockChanges($import);
            if ($skippedLines) {
                $this->warnings[] = "{$skippedLines} line(s) had no SKU or product name and don't affect stock.";
            }

            $dates = collect($this->parsed['orders'])->pluck('placed_at')
                ->merge(collect($this->parsed['expenses'])->pluck('date'))
                ->merge(collect($this->parsed['transactions'])->pluck('occurred_at'))
                ->merge(collect($this->parsed['payouts'])->map(fn ($p) => Carbon::parse($p['paid_on'])))
                ->filter();
            $import->update([
                'path' => $file->storeAs("statements/{$this->channel->user_id}", "{$import->id}-".preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName())),
                'orders_created' => $created,
                'orders_updated' => $updated,
                'expenses' => count($this->parsed['expenses']),
                'transactions' => $transactions,
                'payouts' => $payouts,
                'matched' => $matched,
                'products_created' => $this->productsCreated,
                'units' => $units,
                'period_from' => $dates->min()?->toDateString(),
                'period_to' => $dates->max()?->toDateString(),
                'warnings' => array_values(array_unique(array_slice($this->warnings, 0, 50))),
            ]);

            return $import;
        });

        $this->channel->update(array_filter([
            'last_synced_at' => now(),
            'currency' => $this->channel->currency ?? $this->parsed['currency'],
        ]));
        $this->channel->syncLogs()->create(['job_type' => 'Statement import', 'records' => $import->orders_created + $import->orders_updated + $import->transactions + $import->payouts, 'status' => 'success']);

        return $import;
    }

    /** @return array{0: bool, 1: int, 2: int} [created, units sold, lines without a product] */
    private function upsertOrder(StatementImport $import, array $data): array
    {
        /** @var Order|null $order */
        $order = $this->channel->orders()->with('items')->where('external_id', $data['ref'])->first()
            // An order typed in by hand with the same number is the same order: take it over instead of duplicating it.
            ?? $this->channel->orders()->with('items')->whereNull('external_id')->where('number', $data['number'])->first();

        // A report that only refunds an order we already have just updates the refund.
        if ($order && $data['partial']) {
            $order->update(['refunds' => max($order->refunds, round($data['refunds'], 2)), 'statement_import_id' => $import->id]);
            $order->events()->create(['label' => 'Refund updated from '.$import->filename]);

            return [false, 0, 0];
        }

        // A payments report (no line items) for an order we already have: set its actual money, keep its items and stock.
        if ($order && ($data['money_only'] ?? false)) {
            $order->update([
                'subtotal' => round($data['subtotal'] ?? $order->subtotal, 2),
                'channel_fees' => round($data['fees'] ?? $order->channel_fees, 2),
                'refunds' => round($data['refunds'], 2),
                'fees_actual' => $data['fees'] !== null || $order->fees_actual,
                'statement_import_id' => $import->id,
            ]);
            $order->events()->create(['label' => 'Sales, fees and refunds updated from '.$import->filename]);

            return [false, 0, 0];
        }

        $lines = [];
        $unmatched = 0;
        foreach ($data['lines'] as $line) {
            $product = $this->product($line);
            $unmatched += $product ? 0 : 1;
            $lines[] = [
                'product_id' => $product?->id,
                'sku' => mb_substr(trim($line['sku']) ?: ($product?->sku ?? '—'), 0, 255),
                'name' => mb_substr(trim($line['name']) ?: ($product?->name ?? $line['sku']), 0, 255),
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'unit_cost' => $product?->unit_cost ?? 0,
            ];
        }

        $subtotal = round($data['subtotal'] ?? array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $lines)), 2);
        $deducts = ! $this->channel->auth_type && $data['status'] !== 'cancelled';
        $attributes = [
            'statement_import_id' => $import->id,
            'number' => mb_substr($data['number'], 0, 255),
            'customer_name' => mb_substr($data['customer_name'] ?? $order?->customer_name ?? "{$this->channel->platform} customer", 0, 255),
            'customer_email' => $data['customer_email'] ?? $order?->customer_email,
            'shipping_address' => $data['shipping_address'] ?? $order?->shipping_address,
            'status' => $data['status'],
            'fulfillment' => $data['fulfillment'],
            'stock_deducted' => $deducts,
            'subtotal' => $subtotal,
            'channel_fees' => round($data['fees'] ?? $subtotal * (OrderService::FEE_RATES[$this->channel->platform] ?? 0.1), 2),
            'shipping_cost' => round($data['shipping_cost'], 2),
            'cogs' => round(array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_cost'], $lines)), 2),
            'refunds' => round($data['refunds'], 2),
            'fees_actual' => $data['fees'] !== null,
            'placed_at' => $data['placed_at'],
        ];
        // An orders report only estimates fees: keep the actual money a payments report already recorded.
        if ($data['fees'] === null && $order?->fees_actual) {
            $attributes = array_merge($attributes, $order->only(['subtotal', 'channel_fees', 'refunds']), ['fees_actual' => true]);
        }

        // Undo what the previous version of this order took from stock, then apply the new version.
        if ($order?->stock_deducted) {
            foreach ($order->items as $item) {
                $this->changeStock($item->product_id, $item->quantity);
            }
        } elseif ($order && $order->external_id === null) {
            $this->releaseManualOrder($order);
        }
        if ($deducts) {
            foreach ($lines as $line) {
                $this->changeStock($line['product_id'], -$line['quantity']);
            }
        }

        if ($order) {
            $order->update($attributes);
            $order->items()->delete();
            $order->events()->create(['label' => 'Updated from '.$import->filename]);
        } else {
            $order = $this->channel->user->orders()->create($attributes + ['channel_id' => $this->channel->id, 'external_id' => $data['ref']]);
            $order->events()->create(['label' => 'Order placed', 'created_at' => $data['placed_at']]);
            $order->events()->create(['label' => 'Imported from '.$import->filename]);
        }
        $order->items()->createMany($lines);
        if ($order->external_id === null) {
            $order->update(['external_id' => $data['ref']]);
        }
        // Actual fees and refunds from payment transactions win over the report's estimate.
        PaymentLedger::refreshOrderIfPaid($order);

        return [$order->wasRecentlyCreated, $deducts ? array_sum(array_column($lines, 'quantity')) : 0, $unmatched];
    }

    /** Find the line's product by SKU, then by exact name; create it when only the SKU is new. */
    private function product(array $line): ?Product
    {
        $sku = trim($line['sku']);
        $name = trim($line['name']);

        $product = ($sku !== '' ? $this->bySku[mb_strtolower($sku)] ?? null : null)
            ?? ($name !== '' ? $this->byName[mb_strtolower($name)] ?? null : null);

        if (! $product && $sku !== '') {
            $product = $this->channel->user->products()->create([
                'sku' => mb_substr($sku, 0, 64),
                'name' => mb_substr($name ?: $sku, 0, 255),
                'warehouse' => 'Main warehouse',
                'price' => $line['unit_price'],
                'unit_cost' => 0,
                'on_hand' => 0,
            ]);
            $this->productsCreated++;
            $this->bySku[mb_strtolower($sku)] = $product;
            $this->byName[mb_strtolower($product->name)] ??= $product;
        }

        if ($product) {
            $product->listings()->firstOrCreate(['channel_id' => $this->channel->id], ['external_id' => $sku ?: null, 'allocated' => 0]);
        }

        return $product;
    }

    /**
     * A manually entered order reserves stock while open and takes it off when shipped. Undo that so the
     * imported version can apply its own stock change.
     */
    private function releaseManualOrder(Order $order): void
    {
        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }
            if (in_array($order->status, Order::OPEN_STATUSES, true)) {
                Product::whereKey($item->product_id)->where('reserved', '>=', $item->quantity)->decrement('reserved', $item->quantity);
            } elseif (in_array($order->status, ['shipped', 'delivered'], true)) {
                $this->changeStock($item->product_id, $item->quantity);
            }
        }
    }

    /** @return array{0: int, 1: int, 2: int} [transactions, payouts, records matched to existing ones] */
    private function savePayments(StatementImport $import): array
    {
        $matched = 0;
        $seen = [];
        // Identical rows in one file are separate transactions: number them so each keeps its own identity.
        $occurrence = function (string $key) use (&$seen) {
            return $seen[$key] = ($seen[$key] ?? 0) + 1;
        };

        PaymentLedger::batch(function () use ($import, $occurrence, &$matched) {
            foreach ($this->parsed['transactions'] as $row) {
                $key = 't'.PaymentTransaction::matchKey($row['type'], $row['order_ref'], round($row['amount'], 2), $row['occurred_at']->toDateString());
                [, $wasMatched] = PaymentLedger::saveTransaction($this->channel, $row, $import, $occurrence($key));
                $matched += $wasMatched ? 1 : 0;
            }
        });
        foreach ($this->parsed['payouts'] as $row) {
            $key = 'p'.Payout::matchKey($row['paid_on'], round($row['total'], 2));
            [, $wasMatched] = PaymentLedger::savePayout($this->channel, $row, $import, $occurrence($key));
            $matched += $wasMatched ? 1 : 0;
        }

        return [count($this->parsed['transactions']), count($this->parsed['payouts']), $matched];
    }

    private function changeStock(?int $productId, int $quantity): void
    {
        if ($productId) {
            $this->stockChanges[$productId] = ($this->stockChanges[$productId] ?? 0) + $quantity;
        }
    }

    private function applyStockChanges(StatementImport $import): void
    {
        $short = [];
        foreach (array_filter($this->stockChanges) as $productId => $quantity) {
            $product = Product::find($productId);
            if ($product->on_hand + $quantity < 0) {
                $short[] = $product->sku;
            }
            $product->adjustStock($quantity, $quantity < 0 ? "Sold on {$this->channel->name}" : 'Statement correction', $import->filename);
        }

        if ($this->productsCreated) {
            $this->warnings[] = "{$this->productsCreated} new product(s) were created from SKUs in this file. Set their cost and opening stock on the Inventory page.";
        }
        if ($short) {
            $this->warnings[] = 'Sold more than was in stock (stock set to 0): '.implode(', ', array_slice($short, 0, 15)).(count($short) > 15 ? '…' : '').'. Upload opening stock to correct this.';
        }
    }
}
