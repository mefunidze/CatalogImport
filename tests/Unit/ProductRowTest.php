<?php

namespace Tests\Unit;

use App\Imports\ProductRow;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductRowTest extends TestCase
{
    public function test_normalizes_a_valid_row(): void
    {
        $row = (new ProductRow)->parse([' kb-01 ', " K\u{00E9}yboard ", '12.5', '0']);
        $this->assertSame(['sku' => 'KB-01', 'name' => "K\u{00E9}yboard", 'price' => '12.50', 'quantity' => 0], $row);
    }

    public function test_preserves_decimal_precision_without_float_conversion(): void
    {
        $row = (new ProductRow)->parse(['SKU', 'Name', '99999999.99', '9999999']);
        $this->assertSame('99999999.99', $row['price']);
        $this->assertSame(9999999, $row['quantity']);
    }

    public static function prices(): array
    {
        return [['0', '0.00'], ['1', '1.00'], ['0.1', '0.10'], ['0.01', '0.01'], ['19.90', '19.90']];
    }

    #[DataProvider('prices')]
    public function test_formats_prices(string $input, string $expected): void
    {
        $this->assertSame($expected, (new ProductRow)->parse(['SKU', 'Name', $input, '1'])['price']);
    }

    public static function invalidRows(): iterable
    {
        yield 'too few fields' => [['SKU', 'Name', '10']];
        yield 'too many fields' => [['SKU', 'Name', '10', '1', 'extra']];
        yield 'blank line' => [[null]];
        yield 'empty sku' => [['', 'Name', '10', '1']];
        yield 'sku whitespace' => [['SKU 1', 'Name', '10', '1']];
        yield 'sku punctuation' => [['=CMD()', 'Name', '10', '1']];
        yield 'long sku' => [[str_repeat('a', 65), 'Name', '10', '1']];
        yield 'empty name' => [['SKU', '', '10', '1']];
        yield 'long name' => [['SKU', str_repeat("\u{00E9}", 201), '10', '1']];
        yield 'invalid utf8' => [['SKU', "\xFF", '10', '1']];
        yield 'control character' => [['SKU', "Name\nOther", '10', '1']];
        yield 'negative price' => [['SKU', 'Name', '-1', '1']];
        yield 'exponent' => [['SKU', 'Name', '1e3', '1']];
        yield 'comma decimal' => [['SKU', 'Name', '1,50', '1']];
        yield 'too many decimals' => [['SKU', 'Name', '1.001', '1']];
        yield 'overflow price' => [['SKU', 'Name', '100000000', '1']];
        yield 'leading zero price' => [['SKU', 'Name', '01', '1']];
        yield 'negative quantity' => [['SKU', 'Name', '10', '-1']];
        yield 'decimal quantity' => [['SKU', 'Name', '10', '1.5']];
        yield 'quantity overflow' => [['SKU', 'Name', '10', '10000000']];
        yield 'empty quantity' => [['SKU', 'Name', '10', '']];
    }

    #[DataProvider('invalidRows')]
    public function test_rejects_invalid_rows(array $row): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ProductRow)->parse($row);
    }
}
