<?php

namespace App\Imports;

use App\Jobs\ProcessImport;
use App\Models\Import;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StartImport
{
    public function __construct(private CsvReader $reader) {}

    public function start(UploadedFile $file): Import
    {
        $offset = $this->reader->headerOffset($file->getRealPath());
        $checksum = hash_file('sha256', $file->getRealPath());
        if ($existing = Import::where('checksum', $checksum)->first()) {
            return $existing;
        }
        $path = $file->store('imports', 'local');
        if ($path === false) {
            throw new \RuntimeException('Could not save the file.');
        }
        try {
            $import = DB::transaction(function () use ($file, $path, $checksum, $offset) {
                $import = Import::firstOrCreate(['checksum' => $checksum], [
                    'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'path' => $path,
                    'byte_offset' => $offset,
                ]);
                if ($import->wasRecentlyCreated) {
                    ProcessImport::dispatch($import->id);
                }

                return $import;
            });
            if (! $import->wasRecentlyCreated) {
                Storage::disk('local')->delete($path);
            }

            return $import->refresh();
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }
}
