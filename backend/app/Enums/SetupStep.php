<?php

namespace App\Enums;

/** Setup guide steps, in order (Shopify guidance: five steps at most). */
enum SetupStep: string
{
    case ImportData = 'import_data';
    case LeadTime = 'lead_time';
    case ReviewForecast = 'review_forecast';
    case Suppliers = 'suppliers';
    case Alerts = 'alerts';

    /** Steps a merchant may skip ("I don't use suppliers"). */
    public function skippable(): bool
    {
        return in_array($this, [self::Suppliers, self::Alerts], true);
    }
}
