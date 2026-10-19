<?php

namespace App\Imports;

use App\Jobs\ProcessImport;
use App\Models\Import;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ProcessBatch
{
    public function __construct(private CsvReader $reader, private ProductRow $parser) {}

    public function run(int $id): void
    {
        DB::transaction(function () use ($id) {
            $import = Import::lockForUpdate()->findOrFail($id);
            if (in_array($import->status, ['completed', 'failed'], true)) {
                return;
            }
            $products = [];
            $errors = [];
            $read = 0;
            $valid = 0;
            $offset = $import->byte_offset;
            $limit = max(1, (int) config('imports.batch_size'));
            $now = now();
            foreach ($this->reader->rows(Storage::disk('local')->path($import->path), $offset) as $row) {
                $read++;
                $offset = $row['offset'];
                try {
                    $product = $this->parser->parse($row['values']);
                    $products[$product['sku']] = $product + ['created_at' => $now, 'updated_at' => $now];
                    $valid++;
                } catch (InvalidArgumentException $error) {
                    $errors[] = [
                        'import_id' => $id,
                        'row_number' => $import->processed_rows + $read + 1,
                        'message' => $error->getMessage(),
                    ];
                }
                if ($read >= $limit) {
                    break;
                }
            }
            if ($products !== []) {
                DB::table('products')->upsert(array_values($products), ['sku'], ['name', 'price', 'quantity', 'updated_at']);
            }
            if ($errors !== []) {
                DB::table('import_errors')->insert($errors);
            }
            $completed = $read < $limit;
            $import->update([
                'status' => $completed ? 'completed' : 'processing',
                'byte_offset' => $offset,
                'processed_rows' => $import->processed_rows + $read,
                'valid_rows' => $import->valid_rows + $valid,
                'invalid_rows' => $import->invalid_rows + count($errors),
                'last_error' => null,
                'completed_at' => $completed ? $now : null,
            ]);
            if (! $completed) {
                // Database queue shares this transaction with the checkpoint.
                ProcessImport::dispatch($id);
            }
        }, attempts: 3);
    }
}
