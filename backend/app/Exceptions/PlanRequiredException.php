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
        parent::__construct("This feature is included in the {$feature->minimumPlan()->name} plan.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'feature' => $this->feature->value,
            'required_plan' => $this->feature->minimumPlan()->value,
        ], 402);
    }
}
