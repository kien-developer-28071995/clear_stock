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
    | 'purchase_orders'
    | 'supplier_emails'
    | 'locations'
    | 'transfers'
    | 'realtime_alerts'
    | 'flow_triggers';

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
}
