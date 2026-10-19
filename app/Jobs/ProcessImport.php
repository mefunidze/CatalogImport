<?php

namespace App\Jobs;

use App\Imports\ProcessBatch;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $importId) {}

    public function backoff(): array
    {
        return [5, 15];
    }

    public function handle(ProcessBatch $processor): void
    {
        $processor->run($this->importId);
    }

    public function failed(?Throwable $error): void
    {
        Import::whereKey($this->importId)->whereNot('status', 'completed')->update([
            'status' => 'failed',
            'last_error' => 'Processing failed. Check the logs and retry the import.',
        ]);
    }
}
