import type { Coded } from '@/types/coded';

export type Plan = 'free' | 'starter' | 'growth';
export type SyncStatus = 'pending' | 'running' | 'completed' | 'failed';

export interface Entitlements {
    plan: Plan;
    max_skus: number | null;
    bundles: boolean;
    explanations: boolean;
    alerts: boolean;
    locations: boolean;
    purchase_orders: boolean;
    realtime_alerts: boolean;
    what_if: boolean;
    /** Automatic weekly orders to suppliers (emailing one by hand is purchase_orders). */
    supplier_auto_email: boolean;
    flow_triggers: boolean;
    reference_products: boolean;
    transfers: boolean;
    supplier_emails: boolean;
    abc: boolean;
    /** One-off sales spikes capped before averaging (every plan). */
    spike_filter: boolean;
    /** Sales lost while out of stock (every plan). */
    lost_sales: boolean;
    /** 12-week order and spend plan (Starter and up). */
    purchase_plan: boolean;
    /** Past forecasts vs what really sold (every plan). */
    accuracy: boolean;
    /** Promotions / known sales changes in the forecast (every plan). */
    sales_events: boolean;
    /** Monthly purchasing budget and priorities (Starter and up). */
    order_budget: boolean;
    /** One summary email a week (every plan, opt-in). */
    weekly_summary: boolean;
    /** Shopify's own open purchase orders shown in the app (Starter). */
    shopify_purchase_orders: boolean;
    /** App-wide switches (backend config/features.php): off = hide, don't upsell. */
    features: FeatureSwitches;
}

export type FeatureSwitch =
    | 'what_if'
    | 'reference_products'
    | 'abc'
    | 'purchase_plan'
    | 'spike_filter'
    | 'lost_sales'
    | 'accuracy'
    | 'sales_events'
    | 'order_budget'
    | 'weekly_summary'
    | 'shopify_purchase_orders'
    | 'purchase_orders'
    | 'supplier_emails'
    | 'locations'
    | 'transfers'
    | 'realtime_alerts'
    | 'flow_triggers'
    | 'bundles'
    | 'alerts'
    | 'slack_alerts'
    | 'low_cover_alerts'
    | 'forecast_profiles'
    | 'trend'
    | 'order_exclusions'
    | 'location_exclusions'
    | 'manual_orders'
    | 'alternate_suppliers'
    | 'supplier_import'
    | 'vendor_suppliers'
    | 'costs'
    | 'saved_views'
    | 'product_export'
    | 'stock_history'
    | 'clearance'
    | 'size_runs'
    | 'data_health'
    | 'snooze'
    | 'demand_projection'
    | 'change_log'
    | 'sample_data'
    | 'review_prompt'
    | 'feedback';

export type FeatureSwitches = Record<FeatureSwitch, boolean>;

export interface Shop {
    domain: string;
    name: string | null;
    plan: Plan;
    plan_interval: 'monthly' | 'annual' | null;
    entitlements: Entitlements;
    currency: string | null;
    timezone: string;
    /** Language chosen in Settings; null = follow the Shopify admin language. */
    locale: string | null;
    sync_status: SyncStatus;
    sync_error: Coded | null;
    last_synced_at: string | null;
    installed_at: string | null;
    onboarded: boolean;
    default_lead_time_days: number;
    default_safety_days: number;
    forecasted_at: string | null;
    /** The app may ask for an App Store review now (once per shop, after a finished task). */
    review_prompt: boolean;
}
