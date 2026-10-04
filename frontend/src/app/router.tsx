import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router';
import { useSectionTabs } from '@/components/layout/SectionTabs';
import { HomeRoute } from '@/app/HomeRoute';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { NotFoundPage } from '@/components/layout/NotFoundPage';
import { appConfig } from '@/lib/appConfig';
import { useShopifyNavigation } from '@/hooks/useShopifyNavigation';
import { useFeature } from '@/hooks/useEntitlements';
import type { FeatureSwitch } from '@/features/shop/types';

// Home ships in the main bundle (first paint, Built for Shopify LCP); every other page
// loads on first visit.
const page = <T extends Record<string, unknown>>(load: () => Promise<T>, name: keyof T) =>
    lazy(() => load().then((m) => ({ default: m[name] as React.ComponentType })));

const ReorderPage = page(() => import('@/features/dashboard/pages/ReorderPage'), 'ReorderPage');
const TransfersPage = page(() => import('@/features/transfers/pages/TransfersPage'), 'TransfersPage');
const WhatIfPage = page(() => import('@/features/whatif/pages/WhatIfPage'), 'WhatIfPage');
const PurchasePlanPage = page(() => import('@/features/purchasePlan/pages/PurchasePlanPage'), 'PurchasePlanPage');
const InsightsPage = page(() => import('@/features/dashboard/pages/InsightsPage'), 'InsightsPage');
const ProductsPage = page(() => import('@/features/forecasts/pages/ProductsPage'), 'ProductsPage');
const ProductDetailPage = page(() => import('@/features/forecasts/pages/ProductDetailPage'), 'ProductDetailPage');
const SettingsPage = page(() => import('@/features/settings/pages/SettingsPage'), 'SettingsPage');
const SuppliersPage = page(() => import('@/features/settings/pages/SuppliersPage'), 'SuppliersPage');
const PurchaseOrderImportPage = page(() => import('@/features/imports/pages/PurchaseOrderImportPage'), 'PurchaseOrderImportPage');
const VendorSuppliersPage = page(() => import('@/features/settings/pages/VendorSuppliersPage'), 'VendorSuppliersPage');
const BundlesPage = page(() => import('@/features/settings/pages/BundlesPage'), 'BundlesPage');
const SalesEventsPage = page(() => import('@/features/events/pages/SalesEventsPage'), 'SalesEventsPage');
const ManualOrdersPage = page(() => import('@/features/orders/pages/ManualOrdersPage'), 'ManualOrdersPage');
const CostsPage = page(() => import('@/features/costs/pages/CostsPage'), 'CostsPage');
const BudgetPage = page(() => import('@/features/budget/pages/BudgetPage'), 'BudgetPage');
const DataHealthPage = page(() => import('@/features/reports/pages/DataHealthPage'), 'DataHealthPage');
const PlansPage = page(() => import('@/features/billing/pages/PlansPage'), 'PlansPage');

/** A page of a feature switched off app-wide is simply not there. */
function FeatureRoute({ feature, children }: { feature: FeatureSwitch; children: React.ReactNode }) {
    return useFeature(feature) ? children : <NotFoundPage />;
}

const MOVED: Record<string, string> = {
    '/orders': '/reorder/orders',
    '/transfers': '/reorder/transfers',
    '/bundles': '/products/bundles',
    '/costs': '/products/costs',
    '/purchase-plan': '/planning',
    '/budget': '/planning/budget',
    '/what-if': '/planning/what-if',
    '/events': '/planning/events',
};

/** Redirect that keeps the query string (filters, ?tab=). */
function Moved({ to }: { to: string }) {
    const { search } = useLocation();

    return <Navigate to={to + search} replace />;
}

/** Planning opens on the purchase plan, or on the first planning page that is switched on. */
function PlanningIndex() {
    const tabs = useSectionTabs('planning');
    const purchasePlan = useFeature('purchase_plan');
    if (purchasePlan) return <PurchasePlanPage />;

    return tabs.length > 0 ? <Navigate to={tabs[0].path} replace /> : <NotFoundPage />;
}

export function AppRouter() {
    useShopifyNavigation();

    return (
        <Suspense fallback={<LoadingPage heading={appConfig.appName} />}>
            <Routes>
                <Route path="/" element={<HomeRoute />} />
                <Route path="/reorder" element={<ReorderPage />} />
                <Route path="/reorder/orders" element={<ManualOrdersPage />} />
                <Route path="/reorder/transfers" element={<FeatureRoute feature="transfers"><TransfersPage /></FeatureRoute>} />
                <Route path="/products" element={<ProductsPage />} />
                <Route path="/products/bundles" element={<BundlesPage />} />
                <Route path="/products/costs" element={<CostsPage />} />
                <Route path="/products/:variantId" element={<ProductDetailPage />} />
                <Route path="/insights" element={<InsightsPage />} />
                <Route path="/planning" element={<PlanningIndex />} />
                <Route path="/planning/budget" element={<FeatureRoute feature="order_budget"><BudgetPage /></FeatureRoute>} />
                <Route path="/planning/what-if" element={<FeatureRoute feature="what_if"><WhatIfPage /></FeatureRoute>} />
                <Route path="/planning/events" element={<FeatureRoute feature="sales_events"><SalesEventsPage /></FeatureRoute>} />
                <Route path="/suppliers" element={<SuppliersPage />} />
                <Route path="/suppliers/import" element={<PurchaseOrderImportPage />} />
                <Route path="/suppliers/from-vendors" element={<VendorSuppliersPage />} />
                <Route path="/settings" element={<SettingsPage />} />
                <Route path="/data-health" element={<DataHealthPage />} />
                <Route path="/plans" element={<PlansPage />} />
                {/* Paths from before the pages were grouped (links in emails, bookmarks). */}
                {Object.entries(MOVED).map(([from, to]) => (
                    <Route key={from} path={from} element={<Moved to={to} />} />
                ))}
                <Route path="*" element={<NotFoundPage />} />
            </Routes>
        </Suspense>
    );
}
