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
};

/** What the current plan allows (defaults to Free while loading). */
export function useEntitlements(): Entitlements {
    return useShop().data?.entitlements ?? NONE;
}
