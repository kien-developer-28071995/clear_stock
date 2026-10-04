import type { SectionGroup } from '@/components/layout/SectionTabs';

/**
 * The pages behind each group of section tabs. Loaded in the background as soon as one page of
 * the group is open, so switching tabs never shows a loading page (the tabs must not jump).
 */
const SECTIONS: Record<SectionGroup, (() => Promise<unknown>)[]> = {
    reorder: [
        () => import('@/features/dashboard/pages/ReorderPage'),
        () => import('@/features/orders/pages/ManualOrdersPage'),
        () => import('@/features/transfers/pages/TransfersPage'),
    ],
    products: [
        () => import('@/features/forecasts/pages/ProductsPage'),
        () => import('@/features/settings/pages/BundlesPage'),
        () => import('@/features/costs/pages/CostsPage'),
    ],
    planning: [
        () => import('@/features/purchasePlan/pages/PurchasePlanPage'),
        () => import('@/features/budget/pages/BudgetPage'),
        () => import('@/features/whatif/pages/WhatIfPage'),
        () => import('@/features/events/pages/SalesEventsPage'),
    ],
};

export function preloadSection(group: SectionGroup): void {
    SECTIONS[group].forEach((load) => void load().catch(() => undefined));
}
