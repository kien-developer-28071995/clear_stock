import { ApiError } from '@/lib/http';
import { useStartSync, useSyncStatus } from '@/features/sync/hooks/useSync';
import { formatNumber, timeAgo } from '@/utils/format';

/** Sync progress, last sync time and errors, with a "Sync now" action. */
export function SyncStatusCard() {
    const { data: sync, isPending, error } = useSyncStatus();
    const start = useStartSync();

    if (isPending) {
        return (
            <s-section heading="Store data">
                <s-spinner accessibilityLabel="Loading sync status" />
            </s-section>
        );
    }

    if (error || !sync) {
        return (
            <s-section heading="Store data">
                <s-banner tone="critical">We couldn't load the sync status. Please reload the page.</s-banner>
            </s-section>
        );
    }

    const running = sync.status === 'running';
    const run = sync.run;
    const startError = start.error instanceof ApiError ? start.error.message : null;

    return (
        <s-section heading="Store data">
            <s-stack gap="base">
                {running && run && (
                    <s-stack gap="small-200">
                        <s-text>
                            {run.type === 'initial'
                                ? 'Importing your products, inventory and the last 12 months of orders. You can leave this page; we keep working in the background.'
                                : 'Updating your data from Shopify.'}
                        </s-text>
                        <s-progress value={run.progress} max={100} accessibilityLabel="Sync progress" />
                        <s-text color="subdued">
                            {run.stage_label} · {run.progress}%
                        </s-text>
                    </s-stack>
                )}

                {sync.status === 'failed' && sync.error && (
                    <s-banner tone="critical" heading="Sync failed">
                        <s-paragraph>{sync.error}</s-paragraph>
                    </s-banner>
                )}

                {startError && <s-banner tone="warning">{startError}</s-banner>}

                {!running && (
                    <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
                        <s-text color="subdued">
                            {sync.last_synced_at
                                ? `Last synced ${timeAgo(sync.last_synced_at)}`
                                : 'Not synced yet'}
                            {run?.stats &&
                                ` · ${formatNumber(run.stats.variants)} variants, ${formatNumber(run.stats.orders)} orders`}
                        </s-text>
                        <s-button onClick={() => start.mutate()} loading={start.isPending || undefined}>
                            {sync.status === 'failed' ? 'Retry sync' : 'Sync now'}
                        </s-button>
                    </s-stack>
                )}
            </s-stack>
        </s-section>
    );
}
