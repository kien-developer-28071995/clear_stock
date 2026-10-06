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
    /** Stock transfer suggestions between locations (needs locations). */
    case Transfers = 'transfers';
    /** Emailing a purchase order to a supplier by hand (automatic sending is SupplierAutoEmail). */
    case SupplierEmails = 'supplier_emails';
    /** ABC classes by revenue (every plan; can be switched off app-wide). */
    case Abc = 'abc';
    /** One-off sales spikes capped before averaging (every plan; merchants can switch it off in Settings). */
    case SpikeFilter = 'spike_filter';
    /** Estimated sales lost while out of stock (every plan). */
    case LostSales = 'lost_sales';
    /** Purchase plan: what to order and spend week by week over the next 12 weeks. */
    case PurchasePlan = 'purchase_plan';
    /** Forecast accuracy: past forecasts next to what really sold (every plan). */
    case Accuracy = 'accuracy';
    /** Sales events (promotions, Black Friday) raising or lowering demand on set days (every plan). */
    case SalesEvents = 'sales_events';
    /** Monthly purchasing budget: what to reorder first when cash is short (Starter). */
    case OrderBudget = 'order_budget';
    /** One summary email a week: what to order, cash tied up, lost sales (every plan, opt-in). */
    case WeeklySummary = 'weekly_summary';
    /** Shopify's own open purchase orders shown in the app (Starter; optional scope). */
    case ShopifyPurchaseOrders = 'shopify_purchase_orders';

    /** The cheapest plan that includes it (for upgrade prompts). */
    public function minimumPlan(): Plan
    {
        return match ($this) {
            self::Explanations, self::Abc, self::SpikeFilter, self::LostSales, self::Accuracy, self::SalesEvents, self::WeeklySummary => Plan::Free,
            self::Locations, self::Transfers, self::RealtimeAlerts, self::SupplierAutoEmail, self::FlowTriggers => Plan::Growth,
            default => Plan::Starter,
        };
    }
}
