<?php

namespace App\Imports;

use Generator;
use InvalidArgumentException;
use RuntimeException;

class CsvReader
{
    private const HEADER = ['sku', 'name', 'price', 'quantity'];

    public function headerOffset(string $path): int
    {
        $stream = $this->open($path);
        try {
            $header = fgetcsv($stream, null, ',', '"', '');
            if ($header === false || $header === [null]) {
                throw new InvalidArgumentException('The CSV file is empty.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $header = array_map(static fn ($value) => strtolower(trim($value)), $header);
            if ($header !== self::HEADER) {
                throw new InvalidArgumentException('Expected CSV header: sku,name,price,quantity.');
            }

            return ftell($stream);
        } finally {
            fclose($stream);
        }
    }

    /** @return Generator<int, array{values: array, offset: int}> */
    public function rows(string $path, int $offset): Generator
    {
        $stream = $this->open($path);
        try {
            if (fseek($stream, $offset) !== 0) {
                throw new RuntimeException('Could not restore the file position.');
            }
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                yield ['values' => $values, 'offset' => ftell($stream)];
            }
            if (! feof($stream)) {
                throw new RuntimeException('Could not read the CSV file.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function open(string $path)
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The import file is unavailable.');
        }

        return $stream;
    }
}
