import { Route, Routes } from 'react-router';
import { HomeRoute } from '@/app/HomeRoute';
import { ReorderPage } from '@/features/dashboard/pages/ReorderPage';
import { InsightsPage } from '@/features/dashboard/pages/InsightsPage';
import { ProductsPage } from '@/features/forecasts/pages/ProductsPage';
import { ProductDetailPage } from '@/features/forecasts/pages/ProductDetailPage';
import { SettingsPage } from '@/features/settings/pages/SettingsPage';
import { SuppliersPage } from '@/features/settings/pages/SuppliersPage';
import { PurchaseOrderImportPage } from '@/features/imports/pages/PurchaseOrderImportPage';
import { BundlesPage } from '@/features/settings/pages/BundlesPage';
import { NotFoundPage } from '@/components/layout/NotFoundPage';
import { PlansPage } from '@/features/billing/pages/PlansPage';
import { useShopifyNavigation } from '@/hooks/useShopifyNavigation';

export function AppRouter() {
    useShopifyNavigation();

    return (
        <Routes>
            <Route path="/" element={<HomeRoute />} />
            <Route path="/reorder" element={<ReorderPage />} />
            <Route path="/insights" element={<InsightsPage />} />
            <Route path="/products" element={<ProductsPage />} />
            <Route path="/products/:variantId" element={<ProductDetailPage />} />
            <Route path="/suppliers" element={<SuppliersPage />} />
            <Route path="/suppliers/import" element={<PurchaseOrderImportPage />} />
            <Route path="/bundles" element={<BundlesPage />} />
            <Route path="/settings" element={<SettingsPage />} />
            <Route path="/plans" element={<PlansPage />} />
            <Route path="*" element={<NotFoundPage />} />
        </Routes>
    );
}
