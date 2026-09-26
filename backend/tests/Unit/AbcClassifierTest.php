<?php

use App\Services\Forecast\AbcClassifier;

function classes(array $revenue): array
{
    return array_map(fn ($c) => $c['class'], (new AbcClassifier(0.8, 0.95))->classify($revenue));
}

it('puts the products making up the first 80% of revenue in A, up to 95% in B, the rest in C', function () {
    // Shares: 50, 25, 10, 6, 4, 3, 2 (%)
    $result = classes([1 => 500, 2 => 250, 3 => 100, 4 => 60, 5 => 40, 6 => 30, 7 => 20]);

    // Before each: 0, 50, 75 -> A; 85, 91 -> B; 95, 98 -> C
    expect($result)->toBe([1 => 'A', 2 => 'A', 3 => 'A', 4 => 'B', 5 => 'B', 6 => 'C', 7 => 'C']);
});

it('keeps the product that crosses a threshold in the higher class', function () {
    // A single product with 90% of revenue is A, not B.
    expect(classes([1 => 90, 2 => 10]))->toBe([1 => 'A', 2 => 'B']);
});

it('puts products without revenue in C and stores revenue and share', function () {
    $result = (new AbcClassifier(0.8, 0.95))->classify([1 => 0, 2 => 300.456, 3 => 100]);

    expect($result[1])->toBe(['class' => 'C', 'revenue' => 0.0, 'share' => 0.0])
        ->and($result[2])->toBe(['class' => 'A', 'revenue' => 300.46, 'share' => 0.750285])
        ->and($result[3]['class'])->toBe('A'); // 75% before it
});

it('handles no products and a shop without any revenue', function () {
    expect(classes([]))->toBe([])
        ->and(classes([1 => 0, 2 => 0]))->toBe([1 => 'C', 2 => 'C']);
});

it('ranks equal revenue by id so the result is stable', function () {
    expect(array_keys(classes([5 => 10, 3 => 10, 4 => 10])))->toBe([3, 4, 5]);
});
