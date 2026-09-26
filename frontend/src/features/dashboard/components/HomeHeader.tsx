import { useTranslation } from 'react-i18next';
import type { Dashboard } from '@/features/dashboard/types';
import { useSyncStatus } from '@/features/sync/hooks/useSync';
import { timeAgo } from '@/utils/format';

function greetingKey(): 'home.greetingMorning' | 'home.greetingAfternoon' | 'home.greetingEvening' {
    const h = new Date().getHours();
    return h < 12 ? 'home.greetingMorning' : h < 18 ? 'home.greetingAfternoon' : 'home.greetingEvening';
}

/** What today needs, and whether the app is set up and working (Built for Shopify 4.2.3). */
export function HomeHeader({ dashboard }: { dashboard: Dashboard }) {
    const { t } = useTranslation();
    const { actions } = dashboard;
    const now = actions.out_of_stock.length + actions.order_today.length;
    const sync = useSyncStatus().data;

    return (
        <s-stack gap="small-100">
            <s-heading>
                {t(greetingKey())} {now === 0 ? t('home.nothingToday') : t('home.toReorderToday', { count: now })}
            </s-heading>
            <s-stack direction="inline" gap="small-200" alignItems="center">
                {sync?.status !== 'failed' && sync?.last_synced_at && <s-icon type="check-circle" tone="success" />}
                <s-text color="subdued">
                    {[
                        sync?.last_synced_at ? t('home.dataSynced', { when: timeAgo(sync.last_synced_at) }) : t('home.notSyncedYet'),
                        dashboard.forecasted_at ? t('home.forecastUpdated', { when: timeAgo(dashboard.forecasted_at) }) : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </s-text>
            </s-stack>
        </s-stack>
    );
}
