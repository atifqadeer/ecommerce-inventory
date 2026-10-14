<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Csv;
use App\Services\Statements\Formats\GenericTemplate;
use App\Services\Statements\StatementImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatementImportController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->statementImports()
            ->when($request->filled('channel_id'), fn ($q) => $q->where('channel_id', $request->integer('channel_id')))
            ->with('channel:id,name,platform,color')
            ->latest()->latest('id')
            ->limit($request->integer('limit', 25))
            ->get();
    }

    /** Upload a statement or order report to one store. */
    public function store(Request $request, int $id): JsonResponse
    {
        $channel = $request->user()->channels()->findOrFail($id);
        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:csv,tsv,txt', 'max:51200'],
            'format' => ['nullable', Rule::in(array_keys(StatementImporter::formats()))],
        ]);

        $import = StatementImporter::import($channel, $data['file'], $data['format'] ?? null);

        return response()->json($import->load('channel:id,name,platform,color'), 201);
    }

    /** Remove an upload's orders and charges and return its units to stock. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        StatementImporter::undo($request->user()->statementImports()->findOrFail($id));

        return response()->json(['message' => 'Upload removed.']);
    }

    public function template(): StreamedResponse
    {
        return Csv::download('northstar-statement-template.csv', Csv::fromRows([
            GenericTemplate::HEADERS,
            ['1001', now()->toDateString(), 'TEE-BLK-M', 'Everyday tee / black / M', 2, 24.00, 5.10, 4.50, 0, 'shipped', 'Jane Doe', 'jane@example.com'],
            ['1001', now()->toDateString(), 'MUG-WHT', 'Ceramic mug / white', 1, 18.00, '', '', '', '', '', ''],
        ]));
    }
}
