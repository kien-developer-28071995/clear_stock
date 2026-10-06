<?php

use App\Services\Forecast\BundleRevenue;

it('splits a virtual bundle revenue over its components by quantity x price', function () {
    // Gift box (bundle 9, $100 of sales) = 2 mugs ($10) + 1 plate ($30): weights 20 and 30.
    $out = BundleRevenue::attribute([1 => 50.0, 2 => 10.0], [9 => [1 => 2, 2 => 1]], [9 => 100.0], [1 => 10.0, 2 => 30.0], [1, 2]);

    expect($out)->toBe([1 => 90.0, 2 => 70.0]);
});

it('splits by quantity when a component has no price, and skips tracked bundles', function () {
    expect(BundleRevenue::attribute([], [9 => [1 => 3, 2 => 1]], [9 => 40.0], [1 => 5.0, 2 => null], [1, 2]))->toBe([1 => 30.0, 2 => 10.0])
        // The bundle is a tracked product itself: it is classified on its own sales.
        ->and(BundleRevenue::attribute([9 => 40.0], [9 => [1 => 1]], [9 => 40.0], [1 => 5.0], [1, 9]))->toBe([9 => 40.0])
        // Untracked components get nothing, their share is dropped.
        ->and(BundleRevenue::attribute([], [9 => [1 => 1, 3 => 1]], [9 => 20.0], [1 => 5.0, 3 => 5.0], [1]))->toBe([1 => 10.0]);
});
