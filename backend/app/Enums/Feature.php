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
    /** Growth what-if: sales +/- X% -> what to order. */
    case WhatIf = 'what_if';
    /** Automatic weekly purchase orders to suppliers (sending one by hand is PurchaseOrders). */
    case SupplierAutoEmail = 'supplier_auto_email';
    /** Shopify Flow triggers based on the forecast. */
    case FlowTriggers = 'flow_triggers';
    /** New products forecast from a similar product until they have their own history. */
    case ReferenceProducts = 'reference_products';

    /** The cheapest plan that includes it (for upgrade prompts). */
    public function minimumPlan(): Plan
    {
        return in_array($this, [self::Locations, self::RealtimeAlerts, self::SupplierAutoEmail, self::FlowTriggers], true) ? Plan::Growth : Plan::Starter;
    }
}
