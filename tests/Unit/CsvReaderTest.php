<?php

namespace Tests\Unit;

use App\Imports\CsvReader;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CsvReaderTest extends TestCase
{
    private array $files = [];

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv-');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function test_reads_utf8_bom_and_crlf(): void
    {
        $path = $this->file("\xEF\xBB\xBFSKU, Name, Price, Quantity\r\nA,Mouse,12.50,2\r\n");
        $reader = new CsvReader;
        $rows = iterator_to_array($reader->rows($path, $reader->headerOffset($path)));
        $this->assertCount(1, $rows);
        $this->assertSame(['A', 'Mouse', '12.50', '2'], $rows[0]['values']);
    }

    public function test_handles_quoted_commas_quotes_and_newlines(): void
    {
        $path = $this->file("sku,name,price,quantity\nA,\"Mouse, \"\"Pro\"\"\",12,2\nB,\"Two\nlines\",20,1\n");
        $reader = new CsvReader;
        $rows = iterator_to_array($reader->rows($path, $reader->headerOffset($path)));
        $this->assertSame('Mouse, "Pro"', $rows[0]['values'][1]);
        $this->assertSame("Two\nlines", $rows[1]['values'][1]);
    }

    public function test_resumes_from_an_exact_record_boundary(): void
    {
        $path = $this->file("sku,name,price,quantity\nA,First,1,1\nB,Second,2,2\n");
        $reader = new CsvReader;
        $all = iterator_to_array($reader->rows($path, $reader->headerOffset($path)));
        $resumed = iterator_to_array($reader->rows($path, $all[0]['offset']));
        $this->assertCount(1, $resumed);
        $this->assertSame('B', $resumed[0]['values'][0]);
    }

    public function test_returns_a_generator_instead_of_loading_all_records(): void
    {
        $path = $this->file("sku,name,price,quantity\nA,Name,1,1\n");
        $this->assertInstanceOf(Generator::class, (new CsvReader)->rows($path, 0));
    }

    public function test_accepts_header_only_file(): void
    {
        $path = $this->file('sku,name,price,quantity');
        $reader = new CsvReader;
        $this->assertSame([], iterator_to_array($reader->rows($path, $reader->headerOffset($path))));
    }

    public function test_rejects_wrong_header(): void
    {
        $path = $this->file("name,sku,price,quantity\n");
        $this->expectException(InvalidArgumentException::class);
        (new CsvReader)->headerOffset($path);
    }

    public function test_rejects_an_empty_file(): void
    {
        $path = $this->file('');
        $this->expectException(InvalidArgumentException::class);
        (new CsvReader)->headerOffset($path);
    }

    public function test_missing_file_fails_clearly(): void
    {
        $this->expectException(RuntimeException::class);
        (new CsvReader)->headerOffset('/missing/import.csv');
    }
}
