import { Route, Routes } from 'react-router';
import { HomeRoute } from '@/app/HomeRoute';
import { ProductsPage } from '@/features/forecasts/pages/ProductsPage';
import { ProductDetailPage } from '@/features/forecasts/pages/ProductDetailPage';
import { SettingsPage } from '@/features/settings/pages/SettingsPage';
import { SuppliersPage } from '@/features/settings/pages/SuppliersPage';
import { BundlesPage } from '@/features/settings/pages/BundlesPage';
import { NotFoundPage } from '@/components/layout/NotFoundPage';
import { PlansPage } from '@/features/billing/pages/PlansPage';
import { useShopifyNavigation } from '@/hooks/useShopifyNavigation';

export function AppRouter() {
    useShopifyNavigation();

    return (
        <Routes>
            <Route path="/" element={<HomeRoute />} />
            <Route path="/products" element={<ProductsPage />} />
            <Route path="/products/:variantId" element={<ProductDetailPage />} />
            <Route path="/suppliers" element={<SuppliersPage />} />
            <Route path="/bundles" element={<BundlesPage />} />
            <Route path="/settings" element={<SettingsPage />} />
            <Route path="/plans" element={<PlansPage />} />
            <Route path="*" element={<NotFoundPage />} />
        </Routes>
    );
}
