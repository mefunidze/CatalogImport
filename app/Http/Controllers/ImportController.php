<?php

namespace App\Http\Controllers;

use App\Imports\StartImport;
use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public int $pagination = 15;

    public function index(): JsonResponse
    {
        return response()->json(Import::latest('id')->paginate($this->pagination));
    }

    public function store(Request $request, StartImport $starter): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'extensions:csv', 'max:' . config('imports.max_upload_kb')]]);
        try {
            $import = $starter->start($request->file('file'));
        } catch (InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], Response::HTTP_ACCEPTED);
        }

        return response()->json(['data' => $import, 'duplicate' => !$import->wasRecentlyCreated], $import->wasRecentlyCreated ? Response::HTTP_ACCEPTED : Response::HTTP_OK);
    }

    public function show(Import $import): JsonResponse
    {
        return response()->json(['data' => $import, 'errors' => $import->errors()->orderBy('row_number')->limit(20)->get(['row_number', 'message'])]);
    }

    public function retry(Import $import): JsonResponse
    {
        $import = DB::transaction(function () use ($import) {
            $locked = Import::lockForUpdate()->findOrFail($import->id);
            abort_unless($locked->status === 'failed', Response::HTTP_CONFLICT, 'Only failed imports can be retried.');
            $locked->update(['status' => 'queued', 'last_error' => null]);
            ProcessImport::dispatch($locked->id);

            return $locked;
        });

        return response()->json(['data' => $import], Response::HTTP_ACCEPTED);
    }

    public function errors(Import $import): StreamedResponse
    {
        return response()->streamDownload(function () use ($import) {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, ['row_number', 'message'], ',', '"', '');
            foreach ($import->errors()->orderBy('id')->lazyById(500) as $error) {
                fputcsv($stream, [$error->row_number, $error->message], ',', '"', '');
            }
            fclose($stream);
        }, 'import-' . $import->id . '-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function products(): JsonResponse
    {
        return response()->json(Product::orderBy('sku')->paginate($this->pagination));
    }
}
