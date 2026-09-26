import { useTranslation } from 'react-i18next';
import { ApiError } from '@/lib/http';
import { useStartSync, useSyncStatus } from '@/features/sync/hooks/useSync';
import { timeAgo } from '@/utils/format';

/** Sync progress, last sync time and errors, with a "Sync now" action. */
export function SyncStatusCard() {
    const { t } = useTranslation();
    const { data: sync, isPending, error } = useSyncStatus();
    const start = useStartSync();

    if (isPending) {
        return (
            <s-section heading={t('sync.heading')}>
                <s-spinner accessibilityLabel={t('sync.loading')} />
            </s-section>
        );
    }

    if (error || !sync) {
        return (
            <s-section heading={t('sync.heading')}>
                <s-banner tone="critical">{t('sync.loadFailed')}</s-banner>
            </s-section>
        );
    }

    const running = sync.status === 'running';
    const run = sync.run;
    const startError = start.error instanceof ApiError ? start.error.message : null;

    return (
        <s-section heading={t('sync.heading')}>
            <s-stack gap="base">
                {running && run && (
                    <s-stack gap="small-200">
                        <s-text>{run.type === 'initial' ? t('sync.importingInitial') : t('sync.updating')}</s-text>
                        <s-progress value={run.progress} max={100} accessibilityLabel={t('sync.progressLabel')} />
                        <s-text color="subdued">
                            {run.stage_label} · {run.progress}%
                        </s-text>
                    </s-stack>
                )}

                {sync.status === 'failed' && sync.error && (
                    <s-banner tone="critical" heading={t('sync.failed')}>
                        <s-paragraph>{sync.error}</s-paragraph>
                    </s-banner>
                )}

                {startError && <s-banner tone="warning">{startError}</s-banner>}

                {!running && (
                    <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
                        <s-text color="subdued">
                            {sync.last_synced_at ? t('sync.lastSynced', { when: timeAgo(sync.last_synced_at) }) : t('sync.notSynced')}
                            {run?.stats && ` · ${t('sync.stats', { variants: run.stats.variants, orders: run.stats.orders })}`}
                        </s-text>
                        <s-button onClick={() => start.mutate()} loading={start.isPending || undefined}>
                            {sync.status === 'failed' ? t('sync.retry') : t('sync.syncNow')}
                        </s-button>
                    </s-stack>
                )}
            </s-stack>
        </s-section>
    );
}
