<?php

namespace App\Exceptions;

use App\Enums\Feature;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The shop's plan does not include a feature. Rendered as 402 with an upgrade hint. */
class PlanRequiredException extends RuntimeException
{
    public function __construct(public readonly Feature $feature)
    {
        parent::__construct("plan_required: {$feature->value}");
    }

    public function render(): JsonResponse
    {
        return ApiErrorResponse::make('plan_required', 402, [
            'feature' => $this->feature->value,
            'plan' => $this->feature->minimumPlan()->value,
        ]);
    }
}
