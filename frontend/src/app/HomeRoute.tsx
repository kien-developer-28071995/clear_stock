import { appConfig } from '@/lib/appConfig';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { useShop } from '@/features/shop/hooks/useShop';
import { useDashboard } from '@/features/dashboard/hooks/useDashboard';
import { OnboardingPage } from '@/features/onboarding/pages/OnboardingPage';
import { DashboardPage } from '@/features/dashboard/pages/DashboardPage';

/** New installs see onboarding first; everyone else lands on the dashboard. */
export function HomeRoute() {
    const { data: shop, isPending, error, refetch } = useShop();
    // Started now, not after the shop arrived: one round trip less before the dashboard shows.
    useDashboard();

    if (isPending) return <LoadingPage heading={appConfig.appName} />;
    if (error || !shop) {
        return (
            <s-page heading={appConfig.appName}>
                <ErrorBanner error={error} onRetry={() => refetch()} />
            </s-page>
        );
    }

    return shop.onboarded ? <DashboardPage /> : <OnboardingPage />;
}
