import { useTranslation } from 'react-i18next';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { UpgradePrompt } from '@/components/ui/UpgradePrompt';
import { useEntitlements } from '@/hooks/useEntitlements';
import { TransferRouteCard } from '@/features/transfers/components/TransferRouteCard';
import { useTransfers } from '@/features/transfers/hooks/useTransfers';
import { formatNumber, timeAgo } from '@/utils/format';

/**
 * Growth: stock to move between locations before ordering more, from the forecasts per
 * location. Each route becomes a draft transfer in Shopify (Products > Transfers).
 */
export function TransfersPage() {
    const { t } = useTranslation();
    const { locations: allowed } = useEntitlements();
    const { data, isPending, error, refetch } = useTransfers(allowed);

    if (!allowed) {
        return (
            <s-page heading={t('nav.transfers')}>
                <UpgradePrompt id="transfers" plan="growth">{t('transfers.locked')}</UpgradePrompt>
                <s-section>
                    <s-paragraph>{t('transfers.intro')}</s-paragraph>
                </s-section>
            </s-page>
        );
    }
    if (isPending) return <LoadingPage heading={t('nav.transfers')} />;

    return (
        <s-page heading={t('nav.transfers')}>
            {error && <ErrorBanner error={error} onRetry={() => refetch()} />}
            {data && (
                <s-stack gap="base">
                    <s-paragraph>{t('transfers.intro')}</s-paragraph>

                    {!data.available ? (
                        <s-section>
                            <s-paragraph>{t('transfers.needsLocations')}</s-paragraph>
                        </s-section>
                    ) : data.routes.length === 0 ? (
                        <s-section>
                            <s-empty-state heading={t('transfers.noneHeading')}>
                                <s-paragraph slot="subheading">{t('transfers.noneBody')}</s-paragraph>
                            </s-empty-state>
                        </s-section>
                    ) : (
                        data.routes.map((route) => <TransferRouteCard key={`${route.origin.id}-${route.destination.id}`} route={route} />)
                    )}

                    {data.recent.length > 0 && (
                        <s-section heading={t('transfers.recentHeading')}>
                            <s-stack gap="base">
                                <s-text color="subdued">{t('transfers.recentHelp', { days: 7 })}</s-text>
                                <s-table>
                                    <s-table-header-row>
                                        <s-table-header listSlot="primary">{t('transfers.transfer')}</s-table-header>
                                        <s-table-header>{t('transfers.fromTo')}</s-table-header>
                                        <s-table-header format="numeric">{t('transfers.units')}</s-table-header>
                                        <s-table-header>{t('transfers.created_at')}</s-table-header>
                                    </s-table-header-row>
                                    <s-table-body>
                                        {data.recent.map((r) => (
                                            <s-table-row key={r.id}>
                                                <s-table-cell>
                                                    <s-link href={`shopify://admin/transfers/${r.shopify_transfer_id}`} target="_top">
                                                        {r.name || `#${r.shopify_transfer_id}`}
                                                    </s-link>
                                                </s-table-cell>
                                                <s-table-cell>{t('transfers.route', { origin: r.origin ?? '—', destination: r.destination ?? '—' })}</s-table-cell>
                                                <s-table-cell>{formatNumber(r.total_units, 0)}</s-table-cell>
                                                <s-table-cell>{timeAgo(r.created_at)}</s-table-cell>
                                            </s-table-row>
                                        ))}
                                    </s-table-body>
                                </s-table>
                            </s-stack>
                        </s-section>
                    )}
                </s-stack>
            )}
        </s-page>
    );
}
