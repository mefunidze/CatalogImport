<?php

namespace Tests\Feature;

use App\Imports\CsvReader;
use App\Imports\ProcessBatch;
use App\Imports\ProductRow;
use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function upload(string $rows, string $name = 'products.csv')
    {
        return $this->postJson('/api/imports', ['file' => UploadedFile::fake()->createWithContent($name, "sku,name,price,quantity\n".$rows)]);
    }

    private function process(Import $import): void
    {
        $attempts = 0;
        do {
            app(ProcessBatch::class)->run($import->id);
            $import->refresh();
            $this->assertLessThan(50, ++$attempts);
        } while (! in_array($import->status, ['completed', 'failed'], true));
    }

    public function test_upload_saves_file_and_dispatches_one_job(): void
    {
        Queue::fake();
        $response = $this->upload("A,Mouse,12.50,2\n")->assertStatus(202)->assertJsonPath('duplicate', false)->assertJsonPath('data.status', 'queued')->assertJsonPath('data.processed_rows', 0);
        $import = Import::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($import->path);
        Queue::assertPushed(ProcessImport::class, 1);
        $response->assertJsonMissingPath('data.path')->assertJsonMissingPath('data.checksum');
    }

    public function test_duplicate_file_does_not_create_another_job_or_file(): void
    {
        Queue::fake();
        $first = $this->upload("A,Mouse,12.50,2\n");
        $this->upload("A,Mouse,12.50,2\n", 'renamed.csv')->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('imports', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('imports'));
        Queue::assertPushed(ProcessImport::class, 1);
    }

    public function test_rejects_invalid_header_without_storing_a_file(): void
    {
        Queue::fake();
        $file = UploadedFile::fake()->createWithContent('bad.csv', "name,price\nMouse,10\n");
        $this->postJson('/api/imports', ['file' => $file])->assertUnprocessable();
        $this->assertDatabaseCount('imports', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_requires_a_csv_file(): void
    {
        $this->postJson('/api/imports', [])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload('A,Mouse,1,1', 'file.exe')->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_rejects_oversized_upload(): void
    {
        $this->postJson('/api/imports', ['file' => UploadedFile::fake()->create('large.csv', 51201)])->assertUnprocessable();
    }

    public function test_imports_valid_rows_and_reports_invalid_rows(): void
    {
        Queue::fake();
        $response = $this->upload("A,Mouse,12.50,2\nB,Wrong,abc,2\nC,Keyboard,30,4\n");
        $import = Import::findOrFail($response->json('data.id'));
        $this->process($import);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['sku' => 'A', 'quantity' => 2]);
        $this->getJson('/api/imports/'.$import->id)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.processed_rows', 3)->assertJsonPath('data.valid_rows', 2)->assertJsonPath('data.invalid_rows', 1)->assertJsonPath('errors.0.row_number', 3);
    }

    public function test_last_valid_duplicate_sku_wins_across_batches(): void
    {
        Queue::fake();
        config(['imports.batch_size' => 1]);
        $response = $this->upload("a,First,12.50,2\nA,Second,10,8\n");
        $import = Import::findOrFail($response->json('data.id'));
        $this->process($import);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', ['sku' => 'A', 'name' => 'Second', 'quantity' => 8]);
        $this->assertSame(2, $import->valid_rows);
    }

    public function test_last_valid_duplicate_sku_wins_inside_one_batch(): void
    {
        Queue::fake();
        $import = Import::findOrFail($this->upload("A,First,1,1\na,Second,2,2\n")->json('data.id'));
        $this->process($import);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', ['sku' => 'A', 'name' => 'Second', 'quantity' => 2]);
    }

    public function test_invalid_duplicate_does_not_overwrite_product(): void
    {
        Queue::fake();
        $import = Import::findOrFail($this->upload("A,First,1,1\nA,Second,wrong,2\n")->json('data.id'));
        $this->process($import);
        $this->assertDatabaseHas('products', ['sku' => 'A', 'name' => 'First']);
        $this->assertSame(1, $import->invalid_rows);
    }

    public function test_updates_existing_product_without_changing_created_at(): void
    {
        Queue::fake();
        $product = Product::create(['sku' => 'A', 'name' => 'Old', 'price' => '1.00', 'quantity' => 1, 'created_at' => now()->subDay()]);
        $created = $product->created_at->toDateTimeString();
        $import = Import::findOrFail($this->upload("A,New,5.25,7\n")->json('data.id'));
        $this->process($import);
        $this->assertSame($created, $product->fresh()->created_at->toDateTimeString());
        $this->assertSame('5.25', $product->fresh()->price);
    }

    public function test_completed_job_can_be_delivered_again_without_changing_counters(): void
    {
        Queue::fake();
        $import = Import::findOrFail($this->upload("A,Mouse,1,1\n")->json('data.id'));
        $this->process($import);
        app(ProcessBatch::class)->run($import->id);
        $this->assertSame(1, $import->fresh()->processed_rows);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_checkpoint_resumes_without_reading_committed_rows_again(): void
    {
        Queue::fake();
        config(['imports.batch_size' => 1]);
        $import = Import::findOrFail($this->upload("A,First,1,1\nB,Second,2,2\nC,Third,3,3\n")->json('data.id'));
        app(ProcessBatch::class)->run($import->id);
        $offset = $import->fresh()->byte_offset;
        $this->assertSame(1, $import->fresh()->processed_rows);
        $this->assertSame('processing', $import->fresh()->status);
        $this->process($import);
        $this->assertGreaterThan($offset, $import->byte_offset);
        $this->assertSame(3, $import->processed_rows);
        $this->assertDatabaseCount('products', 3);
    }

    public function test_unexpected_failure_rolls_back_batch_and_checkpoint(): void
    {
        Queue::fake();
        $import = Import::findOrFail($this->upload("A,First,1,1\nB,Second,2,2\n")->json('data.id'));
        $offset = $import->byte_offset;
        $parser = $this->createMock(ProductRow::class);
        $parser->expects($this->exactly(2))->method('parse')->willReturnCallback(function ($values) {
            if ($values[0] === 'B') {
                throw new RuntimeException('Interrupted');
            }

            return (new ProductRow)->parse($values);
        });
        try {
            (new ProcessBatch(new CsvReader, $parser))->run($import->id);
            $this->fail('Expected failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Interrupted', $error->getMessage());
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertSame($offset, $import->fresh()->byte_offset);
        $this->assertSame(0, $import->fresh()->processed_rows);
        $this->process($import);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_missing_file_does_not_advance_checkpoint(): void
    {
        Queue::fake();
        $import = Import::findOrFail($this->upload("A,First,1,1\n")->json('data.id'));
        Storage::disk('local')->delete($import->path);
        try {
            app(ProcessBatch::class)->run($import->id);
            $this->fail('Expected missing file error');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('unavailable', $error->getMessage());
        }
        $this->assertSame(0, $import->fresh()->processed_rows);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_failure_callback_and_manual_retry_keep_checkpoint(): void
    {
        Queue::fake();
        config(['imports.batch_size' => 1]);
        $import = Import::findOrFail($this->upload("A,First,1,1\nB,Second,2,2\n")->json('data.id'));
        app(ProcessBatch::class)->run($import->id);
        $offset = $import->fresh()->byte_offset;
        (new ProcessImport($import->id))->failed(new RuntimeException('Internal error'));
        $this->assertSame('failed', $import->fresh()->status);
        app(ProcessBatch::class)->run($import->id);
        $this->assertSame(1, $import->fresh()->processed_rows);
        $this->postJson('/api/imports/'.$import->id.'/retry')->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $this->assertSame($offset, $import->fresh()->byte_offset);
        $this->process($import);
        $this->assertDatabaseCount('products', 2);
        $this->assertSame(2, $import->processed_rows);
    }

    public function test_cannot_retry_a_running_or_completed_import(): void
    {
        Queue::fake();
        $import = Import::findOrFail($this->upload("A,Name,1,1\n")->json('data.id'));
        $this->postJson('/api/imports/'.$import->id.'/retry')->assertConflict();
        $this->process($import);
        $this->postJson('/api/imports/'.$import->id.'/retry')->assertConflict();
    }

    public function test_header_only_import_finishes_with_zero_rows(): void
    {
        Queue::fake();
        $import = Import::findOrFail($this->upload('')->json('data.id'));
        $this->process($import);
        $this->assertSame(0, $import->processed_rows);
        $this->assertSame('completed', $import->status);
    }

    public function test_error_report_is_downloadable_csv(): void
    {
        Queue::fake();
        $import = Import::findOrFail($this->upload("A,Wrong,abc,1\n")->json('data.id'));
        $this->process($import);
        $response = $this->get('/api/imports/'.$import->id.'/errors');
        $response->assertOk()->assertDownload('import-'.$import->id.'-errors.csv');
        $content = $response->streamedContent();
        $this->assertStringContainsString('row_number,message', $content);
        $this->assertStringContainsString('2,', $content);
    }

    public function test_queue_job_and_checkpoint_commit_together(): void
    {
        config(['imports.batch_size' => 1, 'queue.default' => 'database']);
        $import = Import::findOrFail($this->upload("A,First,1,1\nB,Second,2,2\n")->json('data.id'));
        $this->assertDatabaseCount('jobs', 1);
        app(ProcessBatch::class)->run($import->id);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertSame(1, $import->fresh()->processed_rows);
    }

    public function test_lists_imports_and_products_with_pagination(): void
    {
        Queue::fake();
        $import = Import::findOrFail($this->upload("A,Mouse,1,1\n")->json('data.id'));
        $this->process($import);
        $this->getJson('/api/imports')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.sku', 'A');
        $this->getJson('/api/imports/99999')->assertNotFound();
    }

    public function test_home_and_sample_are_available(): void
    {
        $this->get('/')->assertOk()->assertSee('Product import');
        $this->get('/sample.csv')->assertOk()->assertDownload('products.csv');
    }
}
