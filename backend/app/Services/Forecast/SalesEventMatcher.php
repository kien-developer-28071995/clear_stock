<?php

namespace App\Services\Forecast;

use App\Models\SalesEvent;
use Illuminate\Support\Collection;

/** The shop's sales events that apply to each product (whole shop, its supplier, or picked products). */
final class SalesEventMatcher
{
    /** @param Collection<int, SalesEvent> $events */
    public function __construct(private readonly Collection $events) {}

    public static function none(): self
    {
        return new self(collect());
    }

    /** @return array<int, array{name: string, from: string, to: string, multiplier: float}> */
    public function for(int $variantId, ?int $supplierId): array
    {
        return $this->events->filter(fn (SalesEvent $e) => $e->appliesTo($variantId, $supplierId))
            ->map(fn (SalesEvent $e) => $e->toForecastEvent())->values()->all();
    }
}
