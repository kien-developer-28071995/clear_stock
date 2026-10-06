<?php

namespace App\Services\Forecast;

use App\Models\SalesEvent;
use Illuminate\Support\Collection;

/**
 * The shop's sales events that apply to each product (whole shop, its supplier, or picked products).
 * Yearly seasons are expanded into their occurrences inside the window the forecast looks at.
 */
final class SalesEventMatcher
{
    /**
     * @param  Collection<int, SalesEvent>  $events
     * @param  string  $from  first day of the sales history used
     * @param  string  $until  last day any plan looks ahead to
     */
    public function __construct(private readonly Collection $events, private readonly string $from = '0000-01-01', private readonly string $until = '9999-12-31') {}

    public static function none(): self
    {
        return new self(collect());
    }

    /** @return array<int, array{name: string, from: string, to: string, multiplier: float}> */
    public function for(int $variantId, ?int $supplierId): array
    {
        return $this->events->filter(fn (SalesEvent $e) => $e->appliesTo($variantId, $supplierId))
            ->flatMap(fn (SalesEvent $e) => $e->occurrences($this->from, $this->until))->sortBy('from')->values()->all();
    }
}
