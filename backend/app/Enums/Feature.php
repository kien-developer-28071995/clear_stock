<?php

namespace App\Enums;

/** Plan-gated features (keys of config('billing.plans.*.limits')). */
enum Feature: string
{
    case Bundles = 'bundles';
    case Explanations = 'explanations';
    case Alerts = 'alerts';
    case Locations = 'locations';
    case PurchaseOrders = 'purchase_orders';
    case RealtimeAlerts = 'realtime_alerts';

    /** The cheapest plan that includes it (for upgrade prompts). */
    public function minimumPlan(): Plan
    {
        return in_array($this, [self::Locations, self::PurchaseOrders, self::RealtimeAlerts], true) ? Plan::Growth : Plan::Starter;
    }
}
