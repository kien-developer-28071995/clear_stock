<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Models\SalesEvent;
use App\Models\Shop;
use App\Repositories\Contracts\DailySalesRepositoryInterface;
use App\Repositories\Contracts\SalesEventRepositoryInterface;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;

/**
 * Suggests a sales event for a coming peak from what the shop itself sold in that peak last
 * year: units per day then, against the usual pace of the weeks before it. Only a suggestion:
 * the merchant adds the event (and can change the number), nothing is created here.
 */
class PeakSeasonAdvisor
{
    /** Suggest this many days ahead: orders for the peak have to be placed well before it. */
    private const LOOK_AHEAD_DAYS = 90;

    /** The usual pace: this many days, ending a week before the peak (the week before is already unusual). */
    private const BASELINE_DAYS = 28;

    private const BASELINE_GAP_DAYS = 7;

    /** Under this the "usual pace" is too thin to compare with. */
    private const MIN_BASELINE_UNITS = 28;

    /** A smaller rise is not worth an event. */
    private const MIN_MULTIPLIER = 1.2;

    public function __construct(
        private readonly DailySalesRepositoryInterface $sales,
        private readonly SalesEventRepositoryInterface $events,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function suggestions(Shop $shop, ?CarbonImmutable $today = null): array
    {
        Entitlements::for($shop)->require(Feature::SalesEvents);
        $today = ($today ?? CarbonImmutable::now($shop->timezone))->startOfDay();
        $first = $this->sales->firstSalesDate($shop);
        $out = [];

        foreach ($this->upcoming($today) as $key => [$start, $end, $lastStart, $lastEnd]) {
            $baselineEnd = $lastStart->subDays(self::BASELINE_GAP_DAYS + 1);
            $baselineStart = $baselineEnd->subDays(self::BASELINE_DAYS - 1);
            if ($first === null || $first > $baselineStart->toDateString() || $this->covered($shop, $start, $end)) {
                continue;
            }

            $usual = $this->sales->netUnitsBetween($shop, $baselineStart->toDateString(), $baselineEnd->toDateString());
            if ($usual < self::MIN_BASELINE_UNITS) {
                continue;
            }
            $days = $lastStart->diffInDays($lastEnd) + 1;
            $perDay = $this->sales->netUnitsBetween($shop, $lastStart->toDateString(), $lastEnd->toDateString()) / $days;
            $usualPerDay = $usual / self::BASELINE_DAYS;
            // What an event accepts: 0.1 to 10.
            $multiplier = min(10.0, round($perDay / $usualPerDay, 1));
            if ($multiplier < self::MIN_MULTIPLIER) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(),
                'multiplier' => $multiplier,
                'last_year' => [
                    'starts_on' => $lastStart->toDateString(),
                    'ends_on' => $lastEnd->toDateString(),
                    'units_per_day' => round($perDay, 1),
                    'usual_per_day' => round($usualPerDay, 1),
                ],
            ];
        }

        return $out;
    }

    /**
     * Peaks that start within the look-ahead and have not ended.
     *
     * @return array<string, array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable}> key => this year's and last year's days
     */
    private function upcoming(CarbonImmutable $today): array
    {
        $peaks = [];
        foreach ([$today->year, $today->year + 1] as $year) {
            [$start, $end] = self::blackFridayWeekend($year);
            if ($end >= $today && $start <= $today->addDays(self::LOOK_AHEAD_DAYS)) {
                $peaks['bfcm'] ??= [$start, $end, ...self::blackFridayWeekend($year - 1)];
            }
        }

        return $peaks;
    }

    /** Black Friday (the day after the fourth Thursday of November) to Cyber Monday. */
    public static function blackFridayWeekend(int $year): array
    {
        $november = CarbonImmutable::create($year, 11, 1)->startOfDay();
        $firstThursday = $november->addDays((4 - $november->dayOfWeekIso + 7) % 7);
        $friday = $firstThursday->addDays(22);

        return [$friday, $friday->addDays(3)];
    }

    /** An event of the merchant's already covers (part of) these days. */
    private function covered(Shop $shop, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $this->events->allForShop($shop)->contains(function (SalesEvent $event) use ($start, $end) {
            // A season repeats: compare it as it falls in the year of the peak.
            $shift = $event->repeats_yearly ? $start->year - $event->starts_on->year : 0;
            $from = CarbonImmutable::parse($event->starts_on)->addYears($shift);
            $to = CarbonImmutable::parse($event->ends_on)->addYears($shift);

            return $from <= $end && $to >= $start;
        });
    }
}
