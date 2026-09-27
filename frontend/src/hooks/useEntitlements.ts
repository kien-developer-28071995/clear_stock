import { useShop } from '@/features/shop/hooks/useShop';
import type { Entitlements, FeatureSwitch } from '@/features/shop/types';

/** While the shop loads: nothing paid, every switch on (so nothing flickers away). */
const NONE: Entitlements = {
    plan: 'free',
    max_skus: 50,
    bundles: false,
    explanations: false,
    alerts: false,
    locations: false,
    purchase_orders: false,
    realtime_alerts: false,
    what_if: false,
    supplier_auto_email: false,
    flow_triggers: false,
    reference_products: false,
    transfers: false,
    supplier_emails: false,
    abc: true,
    spike_filter: true,
    lost_sales: true,
    purchase_plan: false,
    accuracy: true,
    sales_events: true,
    order_budget: false,
    features: {
        what_if: true,
        reference_products: true,
        abc: true,
        purchase_plan: true,
        spike_filter: true,
        lost_sales: true,
        accuracy: true,
        sales_events: true,
        order_budget: true,
        purchase_orders: true,
        supplier_emails: true,
        locations: true,
        transfers: true,
        realtime_alerts: true,
        flow_triggers: true,
    },
};

/** What the current plan allows (defaults to Free while loading). Switched-off features are false here too. */
export function useEntitlements(): Entitlements {
    return useShop().data?.entitlements ?? NONE;
}

/**
 * Whether a feature exists in this app at all (backend config/features.php). Off: hide it
 * completely: no menu entry, no upgrade prompt, no pricing line. Plan limits are separate.
 */
export function useFeature(feature: FeatureSwitch): boolean {
    return useEntitlements().features?.[feature] ?? true;
}
