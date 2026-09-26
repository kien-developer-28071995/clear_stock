<?php

use App\Services\Transfer\TransferPlanner;

function loc(int $id, int $stock, float $perDay, int $reorderPoint, int $target, int $incoming = 0, ?string $stockout = null): array
{
    return ['location_id' => $id, 'current_stock' => $stock, 'incoming_stock' => $incoming, 'avg_daily_sales' => $perDay,
        'reorder_point' => $reorderPoint, 'target_stock' => $target, 'stockout_date' => $stockout, 'days_of_cover' => $perDay > 0 ? $stock / $perDay : null];
}

it('moves spare stock to a location that needs to reorder, up to its order-up-to level', function () {
    // Store (1) is below its reorder point (5 <= 20) and wants 40. Warehouse (2) keeps 30 and can spare 70.
    $moves = (new TransferPlanner)->plan([loc(1, 5, 2, 20, 40), loc(2, 100, 1, 15, 30)]);

    expect($moves)->toBe([['origin' => 2, 'destination' => 1, 'quantity' => 35]]);
});

it('never takes more than a location can spare, nor from one that needs stock itself', function () {
    $moves = (new TransferPlanner)->plan([loc(1, 0, 2, 20, 40), loc(2, 38, 1, 15, 30), loc(3, 2, 1, 10, 20)]);

    // 2 can spare 8 (keeps 30); 3 is low itself and gives nothing.
    expect($moves)->toBe([['origin' => 2, 'destination' => 1, 'quantity' => 8]]);
});

it('gives everything a location holds when the product does not sell there', function () {
    expect((new TransferPlanner)->plan([loc(1, 0, 3, 30, 60), loc(2, 25, 0, 0, 0)]))
        ->toBe([['origin' => 2, 'destination' => 1, 'quantity' => 25]]);
});

it('counts stock on the way: at the receiver it lowers the need, at the donor it is not spare yet', function () {
    // Receiver: position 5 + 10 = 15 <= 20, needs 40 - 15 = 25. Donor: on hand 20 only, keeps 30 of a position of 60.
    $moves = (new TransferPlanner)->plan([loc(1, 5, 2, 20, 40, incoming: 10), loc(2, 20, 1, 15, 30, incoming: 40)]);

    expect($moves)->toBe([['origin' => 2, 'destination' => 1, 'quantity' => 20]]);
});

it('serves the location that runs out first, from the locations with the most to spare', function () {
    $moves = (new TransferPlanner)->plan([
        loc(1, 4, 2, 20, 30, stockout: '2026-09-28'),
        loc(2, 1, 2, 20, 30, stockout: '2026-09-21'),
        loc(3, 40, 1, 10, 20),  // spares 20
        loc(4, 25, 1, 10, 20),  // spares 5
    ]);

    expect($moves)->toBe([
        ['origin' => 3, 'destination' => 2, 'quantity' => 20],
        ['origin' => 4, 'destination' => 2, 'quantity' => 5],
    ]);
});

it('treats quantities in recent draft transfers as already moved', function () {
    $planner = new TransferPlanner;
    $locations = [loc(1, 5, 2, 20, 40), loc(2, 100, 1, 15, 30)];

    expect($planner->plan($locations, [['origin' => 2, 'destination' => 1, 'quantity' => 30]]))
        ->toBe([['origin' => 2, 'destination' => 1, 'quantity' => 5]])
        ->and($planner->plan($locations, [['origin' => 2, 'destination' => 1, 'quantity' => 35]]))->toBe([]);
});

it('suggests nothing when every location is fine or nothing can be spared', function () {
    $planner = new TransferPlanner;

    expect($planner->plan([loc(1, 50, 2, 20, 40), loc(2, 100, 1, 15, 30)]))->toBe([])
        ->and($planner->plan([loc(1, 5, 2, 20, 40), loc(2, 10, 1, 15, 30)]))->toBe([])
        ->and($planner->plan([loc(1, 5, 2, 20, 40)]))->toBe([]);
});
