import { useShop } from '@/features/shop/hooks/useShop';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { appConfig } from '@/lib/appConfig';
import { SyncStatusCard } from '@/features/sync/components/SyncStatusCard';

const APP_NAME = appConfig.appName;

/** Temporary home (replaced by onboarding + the "aha" screen in Phase 5). */
export function HomePage() {
    const { data: shop, isPending, error, refetch } = useShop();

    if (isPending) return <LoadingPage heading={APP_NAME} />;

    if (error) {
        return (
            <s-page heading={APP_NAME}>
                <ErrorBanner error={error} onRetry={() => refetch()} />
            </s-page>
        );
    }

    return (
        <s-page heading={APP_NAME}>
            <s-section heading={`Hello, ${shop.name ?? shop.domain} 👋`}>
                <s-paragraph>
                    The app is installed and connected to your store. Inventory forecasts will
                    appear here once the first sync is available.
                </s-paragraph>
            </s-section>
            <SyncStatusCard />
            <s-section heading="Store">
                <s-stack gap="small-200">
                    <s-text>Domain: {shop.domain}</s-text>
                    <s-text>Plan: {shop.plan}</s-text>
                    <s-text>Currency: {shop.currency ?? '—'}</s-text>
                    <s-text>Timezone: {shop.timezone}</s-text>
                </s-stack>
            </s-section>
        </s-page>
    );
}
