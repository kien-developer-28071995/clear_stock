<?php

use App\Services\Flow\FlowTriggerPlanner as P;

const FLOW_TODAY = '2026-09-20';

/** A planning row: 2/day, reorder date and stock-out date as given. */
function flowRow(int $id, ?string $reorder, ?string $stockout, array $overrides = []): array
{
    return $overrides + [
        'variant_id' => $id, 'avg' => 2.0, 'stock' => 20, 'suggested_qty' => 40,
        'reorder_date' => $reorder, 'stockout_date' => $stockout, 'supplier_id' => null,
    ];
}

/** Run the planner over several days, carrying the states: the events of each day by handle. */
function flowDays(array $days): array
{
    $variants = [];
    $suppliers = [];
    $out = [];
    foreach ($days as $today => $rows) {
        $plan = (new P)->plan($rows, $variants, $suppliers, $today);
        $variants = $plan['variant_states'];
        $suppliers = $plan['supplier_states'];
        $out[$today] = array_map(fn ($e) => $e['handle'].':'.$e['subject_id'].(isset($e['level']) ? '@'.$e['level'] : ''), $plan['events']);
    }

    return $out;
}

it('fires the reorder trigger once when a product becomes due, again only after it stopped being due', function () {
    $events = flowDays([
        '2026-09-18' => [flowRow(1, '2026-09-19', '2026-11-30')],   // not due yet
        '2026-09-19' => [flowRow(1, '2026-09-19', '2026-11-30')],   // due
        '2026-09-20' => [flowRow(1, '2026-09-19', '2026-11-30')],   // still due: quiet
        '2026-09-21' => [flowRow(1, '2026-10-30', '2026-12-30')],   // ordered, not due
        '2026-10-30' => [flowRow(1, '2026-10-30', '2026-12-30')],   // due again
    ]);

    expect($events)->toBe([
        '2026-09-18' => [],
        '2026-09-19' => [P::PRODUCT_REORDER.':1'],
        '2026-09-20' => [],
        '2026-09-21' => [],
        '2026-10-30' => [P::PRODUCT_REORDER.':1'],
    ]);
});

it('does not fire the reorder trigger when there is nothing to order', function () {
    expect(flowDays([FLOW_TODAY => [flowRow(1, FLOW_TODAY, null, ['suggested_qty' => 0, 'avg' => 0.0])]])[FLOW_TODAY])->toBe([]);
});

it('fires the stock-out trigger once per threshold as stock runs down', function () {
    $events = flowDays([
        '2026-09-01' => [flowRow(1, null, '2026-10-10')],   // 39 days: nothing
        '2026-09-10' => [flowRow(1, null, '2026-10-10')],   // 30
        '2026-09-11' => [flowRow(1, null, '2026-10-10')],   // 29: same threshold, quiet
        '2026-09-26' => [flowRow(1, null, '2026-10-10')],   // 14
        '2026-10-05' => [flowRow(1, null, '2026-10-10')],   // 5: 7
        '2026-10-10' => [flowRow(1, null, '2026-10-10', ['stock' => 0])], // out of stock
    ]);

    expect(array_values(array_filter(array_merge(...array_values($events)), fn ($e) => str_starts_with($e, P::PRODUCT_STOCKOUT))))
        ->toBe([P::PRODUCT_STOCKOUT.':1@30', P::PRODUCT_STOCKOUT.':1@14', P::PRODUCT_STOCKOUT.':1@7', P::PRODUCT_STOCKOUT.':1@0'])
        ->and($events['2026-09-11'])->toBe([]);
});

it('re-arms the stock-out trigger only when stock recovers clearly above the last threshold', function () {
    $events = flowDays([
        '2026-09-20' => [flowRow(1, null, '2026-09-26')],   // 6 days: 7
        '2026-09-21' => [flowRow(1, null, '2026-09-30')],   // 9 days: not clearly above 7 (needs > 10.4)
        '2026-09-22' => [flowRow(1, null, '2026-09-28')],   // 6 again: quiet
        '2026-09-23' => [flowRow(1, null, '2026-10-20')],   // 27 days: re-armed at 30
        '2026-10-14' => [flowRow(1, null, '2026-10-20')],   // 6 days: fires 7 again
    ]);

    expect($events['2026-09-20'])->toBe([P::PRODUCT_STOCKOUT.':1@7'])
        ->and($events['2026-09-21'])->toBe([])
        ->and($events['2026-09-22'])->toBe([])
        ->and($events['2026-09-23'])->toBe([])
        ->and($events['2026-10-14'])->toBe([P::PRODUCT_STOCKOUT.':1@7']);
});

it('fires the supplier trigger when a product of the supplier becomes due, listing every due product', function () {
    $a = fn (?string $reorder) => flowRow(1, $reorder, '2026-12-01', ['supplier_id' => 9]);
    $b = fn (?string $reorder) => flowRow(2, $reorder, '2026-12-01', ['supplier_id' => 9]);

    $variants = [];
    $suppliers = [];
    $run = function (array $rows, string $today) use (&$variants, &$suppliers) {
        $plan = (new P)->plan($rows, $variants, $suppliers, $today);
        $variants = $plan['variant_states'];
        $suppliers = $plan['supplier_states'];

        return array_values(array_filter($plan['events'], fn ($e) => $e['handle'] === P::SUPPLIER_REORDER));
    };

    $first = $run([$a('2026-09-20'), $b('2026-10-01')], '2026-09-20');
    expect($first)->toHaveCount(1)->and($first[0]['new'])->toBe(1)->and(array_column($first[0]['rows'], 'variant_id'))->toBe([1]);

    expect($run([$a('2026-09-20'), $b('2026-10-01')], '2026-09-21'))->toBe([]);   // same due set

    $second = $run([$a('2026-09-20'), $b('2026-09-22')], '2026-09-22');           // B joins
    expect($second[0]['new'])->toBe(1)->and(array_column($second[0]['rows'], 'variant_id'))->toBe([1, 2]);

    expect($run([$a('2026-10-30'), $b('2026-10-30')], '2026-09-23'))->toBe([])      // both ordered
        ->and($suppliers[9]['due'])->toBe([]);
    expect($run([$a('2026-10-30'), $b('2026-10-30')], '2026-10-30'))->toHaveCount(1); // due again
});

it('ignores products without a supplier for the supplier trigger', function () {
    $plan = (new P)->plan([flowRow(1, FLOW_TODAY, null)], [], [], FLOW_TODAY);

    expect(array_column($plan['events'], 'handle'))->toBe([P::PRODUCT_REORDER]);
});
