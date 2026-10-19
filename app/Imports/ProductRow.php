<?php

namespace App\Imports;

use InvalidArgumentException;

class ProductRow
{
    public function parse(array $values): array
    {
        if (count($values) !== 4) {
            throw new InvalidArgumentException('Expected 4 columns.');
        }
        [$sku, $name, $price, $quantity] = array_map(static fn ($value) => trim((string) $value), $values);
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $sku)) {
            throw new InvalidArgumentException('Invalid SKU.');
        }
        if (! mb_check_encoding($name, 'UTF-8') || $name === '' || mb_strlen($name) > 200 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw new InvalidArgumentException('The name must contain 1–200 UTF-8 characters without control characters.');
        }
        if (! preg_match('/\A(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?\z/D', $price)) {
            throw new InvalidArgumentException('The price must be between 0 and 99999999.99, with a decimal point and up to 2 decimal places.');
        }
        if (! preg_match('/\A(?:0|[1-9]\d{0,6})\z/D', $quantity)) {
            throw new InvalidArgumentException('The quantity must be an integer between 0 and 9999999.');
        }
        [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '');

        return [
            'sku' => strtoupper($sku),
            'name' => $name,
            'price' => $whole.'.'.str_pad($fraction, 2, '0'),
            'quantity' => (int) $quantity,
        ];
    }
}
