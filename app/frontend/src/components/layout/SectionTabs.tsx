import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { preloadSection } from '@/app/preload';
import { useLocation, useNavigate } from 'react-router';
import { Tabs } from '@/components/ui/Tabs';
import { useFeature } from '@/hooks/useEntitlements';

export type SectionGroup = 'reorder' | 'products' | 'planning';

/**
 * Pages that belong together share one menu entry and these tabs (the menu stays short).
 * Each tab is its own route under the group's path, so the menu entry stays highlighted.
 */
export function useSectionTabs(group: SectionGroup): { path: string; label: string }[] {
    const { t } = useTranslation();
    const transfers = useFeature('transfers');
    const purchasePlan = useFeature('purchase_plan');
    const budget = useFeature('order_budget');
    const whatIf = useFeature('what_if');
    const events = useFeature('sales_events');
    const orders = useFeature('manual_orders');
    const bundles = useFeature('bundles');
    const costs = useFeature('costs');

    const all: Record<SectionGroup, { path: string; label: string; on?: boolean }[]> = {
        reorder: [
            { path: '/reorder', label: t('sections.toOrder') },
            { path: '/reorder/orders', label: t('nav.orders'), on: orders },
            { path: '/reorder/transfers', label: t('nav.transfers'), on: transfers },
        ],
        products: [
            { path: '/products', label: t('sections.allProducts') },
            { path: '/products/bundles', label: t('nav.bundles'), on: bundles },
            { path: '/products/costs', label: t('nav.costs'), on: costs },
        ],
        planning: [
            { path: '/planning', label: t('nav.purchasePlan'), on: purchasePlan },
            { path: '/planning/budget', label: t('nav.budget'), on: budget },
            { path: '/planning/what-if', label: t('nav.whatIf'), on: whatIf },
            { path: '/planning/events', label: t('nav.events'), on: events },
        ],
    };

    return all[group].filter((tab) => tab.on !== false);
}

export function SectionTabs({ group }: { group: SectionGroup }) {
    const { t } = useTranslation();
    const tabs = useSectionTabs(group);
    const { pathname } = useLocation();
    const navigate = useNavigate();
    useEffect(() => preloadSection(group), [group]);
    if (tabs.length < 2) return null;

    const go = (path: string) =>
        // Unsaved changes in a save bar: ask before leaving (resolves at once when there are none).
        void (shopify.saveBar?.leaveConfirmation?.() ?? Promise.resolve()).then(() => navigate(path));

    return <Tabs label={t(`nav.${group}`)} tabs={tabs.map((tab) => ({ id: tab.path, label: tab.label }))} value={pathname.replace(/\/$/, '') || '/'} onChange={go} />;
}
