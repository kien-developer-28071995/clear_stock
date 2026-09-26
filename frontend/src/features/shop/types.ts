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
}

export interface Shop {
    domain: string;
    name: string | null;
    plan: Plan;
    plan_interval: 'monthly' | 'annual' | null;
    entitlements: Entitlements;
    currency: string | null;
    timezone: string;
    sync_status: SyncStatus;
    sync_error: string | null;
    last_synced_at: string | null;
    installed_at: string | null;
    onboarded: boolean;
    default_lead_time_days: number;
    default_safety_days: number;
    forecasted_at: string | null;
}
