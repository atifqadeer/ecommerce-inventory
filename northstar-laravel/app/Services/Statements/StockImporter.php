<?php

namespace App\Services\Statements;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports a stock count / product list: creates missing products and sets on-hand stock to the counted
 * quantity (recording the difference as a stock movement). Reads the Northstar template, Shopify's
 * inventory export, Amazon's FBA inventory report and Amazon's FBA Inventory Ledger; rows for the same SKU
 * (e.g. per location) are summed.
 */
class StockImporter
{
    public const HEADERS = ['sku', 'name', 'on_hand', 'unit_cost', 'price', 'warehouse', 'reorder_point'];

    private const COLUMNS = [
        'sku' => ['sku', 'seller-sku', 'seller sku', 'custom label', 'item sku'],
        'name' => ['name', 'product name', 'product-name', 'title', 'item title', 'product'],
        'variant' => ['option1 value'],
        'on_hand' => ['on_hand', 'on hand', 'on hand (current)', 'on hand (new)', 'quantity', 'qty', 'stock', 'available',
            'available (not editable)', 'afn-fulfillable-quantity', 'afn-warehouse-quantity', 'quantity available'],
        'unit_cost' => ['unit_cost', 'unit cost', 'cost', 'cost price', 'cost per item'],
        'price' => ['price', 'selling price', 'your-price', 'variant price'],
        'warehouse' => ['warehouse', 'location'],
        'reorder_point' => ['reorder_point', 'reorder point'],
    ];

    /** @return array{created: int, updated: int, unchanged: int, skipped: int, warnings: array<int, string>} */
    public static function import(User $user, UploadedFile $file): array
    {
        $rows = StatementReader::rows($file->get());
        $warnings = [];
        $skipped = 0;

        if (($ledgerRow = StatementReader::headerRow($rows, self::LEDGER_HEADERS)) !== null) {
            [$items, $warnings] = self::ledgerItems(StatementReader::records($rows, $ledgerRow));

            return self::apply($user, $file, $items, $skipped, $warnings);
        }

        $headerRow = StatementReader::headerRow($rows, [self::COLUMNS['sku'], self::COLUMNS['on_hand']]);
        if ($headerRow === null) {
            throw ValidationException::withMessages(['file' => 'The file needs a SKU column and a quantity column (on_hand, quantity, available…). Download the template for the expected layout.']);
        }

        $records = StatementReader::records($rows, $headerRow);
        $column = fn (string $field) => collect(self::COLUMNS[$field])->first(fn ($name) => array_key_exists($name, $records[0] ?? []));
        $get = fn (array $record, string $field) => ($c = $column($field)) ? trim($record[$c] ?? '') : '';
        $number = fn (string $value) => (float) str_replace(',', '', preg_replace('/[^0-9.,-]/', '', $value));

        $items = [];
        foreach ($records as $record) {
            $sku = $get($record, 'sku');
            if ($sku === '') {
                $skipped++;

                continue;
            }
            $key = mb_strtolower($sku);
            $items[$key] ??= ['sku' => $sku, 'on_hand' => 0, 'fields' => []];
            $items[$key]['on_hand'] += max(0, (int) round($number($get($record, 'on_hand'))));

            $name = $get($record, 'name');
            $variant = $get($record, 'variant');
            $fields = array_filter([
                'name' => $name !== '' ? mb_substr($name.($variant !== '' && $variant !== 'Default Title' ? " / {$variant}" : ''), 0, 255) : null,
                'unit_cost' => $get($record, 'unit_cost') !== '' ? $number($get($record, 'unit_cost')) : null,
                'price' => $get($record, 'price') !== '' ? $number($get($record, 'price')) : null,
                'warehouse' => $get($record, 'warehouse') ?: null,
                'reorder_point' => $get($record, 'reorder_point') !== '' ? (int) $number($get($record, 'reorder_point')) : null,
            ], fn ($v) => $v !== null);
            $items[$key]['fields'] += $fields;
        }

        if ($skipped) {
            $warnings[] = "{$skipped} row(s) without a SKU were skipped.";
        }

        return self::apply($user, $file, $items, $skipped, $warnings);
    }

    private const LEDGER_HEADERS = ['date', 'msku', 'disposition', 'ending warehouse balance'];

    /**
     * Amazon FBA Inventory Ledger (summary view): one row per SKU, disposition, warehouse and day. Stock is the
     * sellable ending balance on the latest date, summed across warehouses. A SKU with no rows that day has none left.
     *
     * @return array{0: array<string, array>, 1: array<int, string>}
     */
    private static function ledgerItems(array $records): array
    {
        $parse = function (string $value): ?string {
            foreach (['n/j/Y', 'm/d/Y', 'Y-m-d'] as $format) {
                $date = \DateTime::createFromFormat('!'.$format, trim($value));
                if ($date) {
                    return $date->format('Y-m-d');
                }
            }

            return null;
        };

        $latest = null;
        $byDate = [];
        $titles = [];
        foreach ($records as $r) {
            $sku = trim($r['msku'] ?? '');
            $date = $parse($r['date'] ?? '');
            if ($sku === '' || ! $date) {
                continue;
            }
            $latest = max($latest, $date);
            $titles[$sku] = $r['title'] ?? $sku;
            $sellable = strtoupper(trim($r['disposition'] ?? '')) === 'SELLABLE';
            $byDate[$date][$sku][$sellable ? 'sellable' : 'unsellable'] = ($byDate[$date][$sku][$sellable ? 'sellable' : 'unsellable'] ?? 0)
                + (int) round((float) ($r['ending warehouse balance'] ?? 0));
        }

        if (! $latest) {
            throw ValidationException::withMessages(['file' => 'No dated rows with an MSKU were found in this inventory ledger.']);
        }

        $items = [];
        $unsellable = [];
        foreach ($titles as $sku => $title) {
            $counts = $byDate[$latest][$sku] ?? [];
            $items[mb_strtolower($sku)] = ['sku' => $sku, 'on_hand' => max(0, $counts['sellable'] ?? 0), 'fields' => [
                'name' => mb_substr($title, 0, 255), 'warehouse' => 'Amazon FBA',
            ]];
            if (($counts['unsellable'] ?? 0) > 0) {
                $unsellable[] = "{$sku} ({$counts['unsellable']})";
            }
        }

        $warnings = ["Stock set to Amazon's sellable count on {$latest}. Upload it after your orders: orders imported later that were placed before {$latest} would be taken off again."];
        if ($unsellable) {
            $warnings[] = 'Unsellable units at Amazon, not counted as stock: '.implode(', ', $unsellable).'.';
        }

        return [$items, $warnings];
    }

    private static function apply(User $user, UploadedFile $file, array $items, int $skipped, array $warnings): array
    {
        if (! $items) {
            throw ValidationException::withMessages(['file' => 'No rows with a SKU were found.']);
        }

        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => $skipped, 'warnings' => $warnings];
        DB::transaction(function () use ($user, $items, $file, &$summary) {
            $existing = $user->products()->get()->keyBy(fn ($p) => mb_strtolower($p->sku));
            foreach ($items as $key => $item) {
                $product = $existing[$key] ?? null;
                if (! $product) {
                    $product = $user->products()->create($item['fields'] + [
                        'sku' => mb_substr($item['sku'], 0, 64), 'name' => $item['sku'], 'warehouse' => 'Main warehouse', 'on_hand' => 0,
                    ]);
                    $summary['created']++;
                } else {
                    $product->update($item['fields']);
                }

                $difference = $item['on_hand'] - $product->on_hand;
                if ($difference !== 0) {
                    $product->adjustStock($difference, 'Stock count import', $file->getClientOriginalName());
                    $product->wasRecentlyCreated || $summary['updated']++;
                } elseif (! $product->wasRecentlyCreated) {
                    $product->wasChanged() ? $summary['updated']++ : $summary['unchanged']++;
                }
            }
        });

        return $summary;
    }
}
