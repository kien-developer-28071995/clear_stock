import { useTranslation } from 'react-i18next';
import { appConfig } from '@/lib/appConfig';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { SyncStatusCard } from '@/features/sync/components/SyncStatusCard';
import { useSyncStatus } from '@/features/sync/hooks/useSync';
import { useDashboard } from '@/features/dashboard/hooks/useDashboard';
import { HomeHeader } from '@/features/dashboard/components/HomeHeader';
import { ActionList } from '@/features/dashboard/components/ActionList';
import { RunwayChart } from '@/features/dashboard/components/RunwayChart';
import { SlowMovers } from '@/features/dashboard/components/SlowMovers';
import { PlanLimitBanner } from '@/features/dashboard/components/PlanLimitBanner';
import { SetupGuide } from '@/features/setup/components/SetupGuide';
import { Tip } from '@/features/setup/components/Tip';

/** Home: today's to-do list first, the overview (runway, slow stock) below. */
export function DashboardPage() {
    const { t } = useTranslation();
    const { data, isPending, error, refetch } = useDashboard();
    const sync = useSyncStatus();

    if (isPending) return <LoadingPage heading={appConfig.appName} />;

    if (error || !data) {
        return (
            <s-page heading={appConfig.appName}>
                <ErrorBanner error={error} onRetry={() => refetch()} />
            </s-page>
        );
    }

    if (data.counts.total === 0) {
        const running = sync.data?.status === 'running';
        return (
            <s-page heading={appConfig.appName}>
                <s-section>
                    <s-empty-state heading={running ? t('home.preparingHeading') : t('home.emptyHeading')}>
                        <s-paragraph slot="subheading">{running ? t('home.preparingBody') : t('home.emptyBody')}</s-paragraph>
                    </s-empty-state>
                </s-section>
                <SetupGuide />
                <SyncStatusCard />
            </s-page>
        );
    }

    return (
        <s-page heading={appConfig.appName}>
            <PlanLimitBanner counts={data.counts} />
            <SetupGuide />
            <s-section>
                <HomeHeader dashboard={data} />
            </s-section>
            <Tip id="home_actions">{t('tips.home_actions')}</Tip>
            <ActionList dashboard={data} />
            <Tip id="home_runway">{t('tips.home_runway')}</Tip>
            <RunwayChart items={data.runway} />
            <SlowMovers dashboard={data} />
            <SyncStatusCard />
        </s-page>
    );
}
