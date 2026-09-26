import type { Entitlements, Plan } from '@/features/shop/types';

export type Interval = 'monthly' | 'annual';

export interface PlanInfo {
    key: Plan;
    name: string;
    prices: Record<Interval, number> | null;
    currency: string;
    limits: Omit<Entitlements, 'plan'>;
}

export interface BillingState {
    plan: Plan;
    interval: Interval | null;
    status: string | null;
    renews_at: string | null;
    entitlements: Entitlements;
    usage: { tracked_skus: number };
    /** Free trial days still available to this shop (granted once). */
    trial_days_left: number;
    plans: PlanInfo[];
}
