<?php

namespace App\Services\Forecast;

/**
 * ABC classification (Pareto): products sorted by revenue, highest first. A = the products
 * that make up the first `a` share of revenue (80%), B = up to `b` (95%), C = the rest.
 * The product that crosses a threshold still belongs to the higher class. No revenue = C.
 */
class AbcClassifier
{
    public function __construct(
        private readonly float $a = 0.8,
        private readonly float $b = 0.95,
    ) {}

    public static function fromConfig(): self
    {
        return new self((float) config('forecast.abc.a'), (float) config('forecast.abc.b'));
    }

    /**
     * @param  array<int, float>  $revenue  variant id => revenue
     * @return array<int, array{class: string, revenue: float, share: float}>
     */
    public function classify(array $revenue): array
    {
        $total = array_sum(array_map(fn ($r) => max(0.0, (float) $r), $revenue));

        $ids = array_keys($revenue);
        // Highest revenue first; ties by id so the result is stable.
        usort($ids, fn ($x, $y) => [(float) $revenue[$y], $x] <=> [(float) $revenue[$x], $y]);

        $out = [];
        $before = 0.0; // cumulative share of the products ranked above
        foreach ($ids as $id) {
            $value = max(0.0, (float) $revenue[$id]);
            $share = $total > 0 ? $value / $total : 0.0;
            $class = match (true) {
                $value <= 0 => 'C',
                $before < $this->a => 'A',
                $before < $this->b => 'B',
                default => 'C',
            };
            $out[$id] = ['class' => $class, 'revenue' => round($value, 2), 'share' => round($share, 6)];
            $before += $share;
        }

        return $out;
    }
}
