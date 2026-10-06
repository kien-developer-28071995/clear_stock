<?php

namespace App\Enums;

/**
 * Forecast inputs a merchant can override from the forecast screen.
 * Precedence (highest first): forecast override > variant setting > supplier > shop default.
 */
enum OverrideField: string
{
    case AvgDailySales = 'avg_daily_sales';
    case LeadTimeDays = 'lead_time_days';
    case SafetyDays = 'safety_days';
}
