import type { ForecastDetail } from '@/features/forecasts/types';
import { useTranslation } from 'react-i18next';
import { ConfidenceBadge, StatusBadge } from '@/components/ui/StatusBadge';
import { AbcBadge } from '@/features/forecasts/components/AbcBadge';
import { useShop } from '@/features/shop/hooks/useShop';
import { formatDate, formatMoney, formatNumber } from '@/utils/format';

function Metric({ label, value }: { label: string; value: string }) {
    return (
        <s-stack gap="small-100">
            <s-text color="subdued">{label}</s-text>
            <s-text type="strong">{value}</s-text>
        </s-stack>
    );
}

export function ForecastSummary({ f }: { f: ForecastDetail }) {
    const { t } = useTranslation();
    const selling = f.avg_daily_sales > 0;
    const currency = useShop().data?.currency ?? null;
    const abc = f.abc;

    return (
        <s-section>
            <s-stack gap="base">
                <s-stack direction="inline" gap="small-200">
                    <StatusBadge status={f.status} />
                    <ConfidenceBadge confidence={f.confidence} />
                    <AbcBadge abc={abc.class} />
                    {f.overrides.avg_daily_sales && <s-badge tone="info">{t('product.adjustedByYou')}</s-badge>}
                    {f.sku && <s-text color="subdued">{t('product.sku', { sku: f.sku })}</s-text>}
                </s-stack>
                {/* Why this class: share of the shop's revenue, at the current price. */}
                <s-text color="subdued">
                    {abc.class
                        ? t(`abc.explain${abc.class}`, {
                              share: formatNumber(abc.share * 100, 1),
                              revenue: formatMoney(abc.revenue, currency),
                              count: abc.days,
                          })
                        : t('abc.noPrice')}
                </s-text>
                <s-grid gridTemplateColumns="@container (inline-size > 500px) 1fr 1fr 1fr 1fr, 1fr 1fr" gap="base">
                    <Metric label={t('table.inStock')} value={formatNumber(f.current_stock, 0)} />
                    {f.incoming_stock > 0 && <Metric label={t('product.onTheWay')} value={formatNumber(f.incoming_stock, 0)} />}
                    <Metric label={t('product.sellsPerDay')} value={formatNumber(f.avg_daily_sales, 2)} />
                    <Metric label={t('slow.daysOfStock')} value={f.days_of_cover === null ? t('slow.noSales') : formatNumber(f.days_of_cover, 0)} />
                    <Metric label={t('product.runsOut')} value={selling ? formatDate(f.stockout_date) : '—'} />
                    <Metric label={t('product.reorderPoint')} value={t('product.units', { count: f.reorder_point, qty: formatNumber(f.reorder_point, 0) })} />
                    <Metric label={t('table.orderBy')} value={selling ? formatDate(f.reorder_date) : '—'} />
                    {f.status === 'overstock' && (
                        <Metric label={t('overstock.excess')} value={t('product.units', { count: f.excess_units, qty: formatNumber(f.excess_units, 0) })} />
                    )}
                    <Metric label={t('table.suggestedOrder')} value={t('product.units', { count: f.suggested_qty, qty: formatNumber(f.suggested_qty, 0) })} />
                    <Metric label={t('table.supplier')} value={f.supplier?.name ?? t('common.notSet')} />
                </s-grid>
            </s-stack>
        </s-section>
    );
}
