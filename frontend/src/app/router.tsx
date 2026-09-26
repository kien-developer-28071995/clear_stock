import { lazy, Suspense } from 'react';
import { Route, Routes } from 'react-router';
import { HomeRoute } from '@/app/HomeRoute';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { NotFoundPage } from '@/components/layout/NotFoundPage';
import { appConfig } from '@/lib/appConfig';
import { useShopifyNavigation } from '@/hooks/useShopifyNavigation';

// Home ships in the main bundle (first paint, Built for Shopify LCP); every other page
// loads on first visit.
const page = <T extends Record<string, unknown>>(load: () => Promise<T>, name: keyof T) =>
    lazy(() => load().then((m) => ({ default: m[name] as React.ComponentType })));

const ReorderPage = page(() => import('@/features/dashboard/pages/ReorderPage'), 'ReorderPage');
const InsightsPage = page(() => import('@/features/dashboard/pages/InsightsPage'), 'InsightsPage');
const ProductsPage = page(() => import('@/features/forecasts/pages/ProductsPage'), 'ProductsPage');
const ProductDetailPage = page(() => import('@/features/forecasts/pages/ProductDetailPage'), 'ProductDetailPage');
const SettingsPage = page(() => import('@/features/settings/pages/SettingsPage'), 'SettingsPage');
const SuppliersPage = page(() => import('@/features/settings/pages/SuppliersPage'), 'SuppliersPage');
const PurchaseOrderImportPage = page(() => import('@/features/imports/pages/PurchaseOrderImportPage'), 'PurchaseOrderImportPage');
const VendorSuppliersPage = page(() => import('@/features/settings/pages/VendorSuppliersPage'), 'VendorSuppliersPage');
const BundlesPage = page(() => import('@/features/settings/pages/BundlesPage'), 'BundlesPage');
const PlansPage = page(() => import('@/features/billing/pages/PlansPage'), 'PlansPage');

export function AppRouter() {
    useShopifyNavigation();

    return (
        <Suspense fallback={<LoadingPage heading={appConfig.appName} />}>
            <Routes>
                <Route path="/" element={<HomeRoute />} />
                <Route path="/reorder" element={<ReorderPage />} />
                <Route path="/insights" element={<InsightsPage />} />
                <Route path="/products" element={<ProductsPage />} />
                <Route path="/products/:variantId" element={<ProductDetailPage />} />
                <Route path="/suppliers" element={<SuppliersPage />} />
                <Route path="/suppliers/import" element={<PurchaseOrderImportPage />} />
                <Route path="/suppliers/from-vendors" element={<VendorSuppliersPage />} />
                <Route path="/bundles" element={<BundlesPage />} />
                <Route path="/settings" element={<SettingsPage />} />
                <Route path="/plans" element={<PlansPage />} />
                <Route path="*" element={<NotFoundPage />} />
            </Routes>
        </Suspense>
    );
}
