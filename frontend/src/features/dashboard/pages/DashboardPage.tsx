import { useTranslation } from 'react-i18next';
import { appConfig } from '@/lib/appConfig';
import { SyncStatusCard } from '@/features/sync/components/SyncStatusCard';
import { useSyncStatus } from '@/features/sync/hooks/useSync';
import { DashboardGate } from '@/features/dashboard/components/DashboardGate';
import { HomeHeader } from '@/features/dashboard/components/HomeHeader';
import { HomeKpis } from '@/features/dashboard/components/HomeKpis';
import { UrgentList } from '@/features/dashboard/components/UrgentList';
import { PlanLimitBanner } from '@/features/dashboard/components/PlanLimitBanner';
import { SetupGuide } from '@/features/setup/components/SetupGuide';

/**
 * Home: only what needs attention. Four numbers, the most urgent products, and the
 * sync card while a sync runs or failed. Details live on Reorder and Insights.
 */
export function DashboardPage() {
    const { t } = useTranslation();
    const sync = useSyncStatus();
    const emptyReason = sync.data?.status === 'running' ? 'preparing' : sync.data?.status === 'failed' ? 'failed' : 'empty';
    const syncNeedsAttention = sync.data?.status === 'running' || sync.data?.status === 'failed';

    return (
        <DashboardGate heading={appConfig.appName}>
            {(data) =>
                data.counts.total === 0 ? (
                    <s-page heading={appConfig.appName}>
                        <s-section>
                            {/* Why there is nothing yet: still importing, the import failed, or the store has nothing to forecast. */}
                            <s-empty-state heading={t(`home.${emptyReason}Heading`)}>
                                <s-paragraph slot="subheading">{t(`home.${emptyReason}Body`)}</s-paragraph>
                            </s-empty-state>
                        </s-section>
                        <SetupGuide />
                        <SyncStatusCard />
                    </s-page>
                ) : (
                    <s-page heading={appConfig.appName}>
                        <PlanLimitBanner counts={data.counts} />
                        {syncNeedsAttention && <SyncStatusCard />}
                        <SetupGuide />
                        <s-stack gap="base">
                            <HomeHeader dashboard={data} />
                            <HomeKpis dashboard={data} />
                            <UrgentList dashboard={data} />
                        </s-stack>
                    </s-page>
                )
            }
        </DashboardGate>
    );
}
