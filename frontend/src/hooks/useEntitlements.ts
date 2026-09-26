import { useShop } from '@/features/shop/hooks/useShop';
import type { Entitlements } from '@/features/shop/types';

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
};

/** What the current plan allows (defaults to Free while loading). */
export function useEntitlements(): Entitlements {
    return useShop().data?.entitlements ?? NONE;
}
