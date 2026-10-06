import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError, errorMessage, http } from '@/lib/http';
import { useClearance } from '@/features/reports/hooks/useReports';
import { formatDate, formatMoney, formatNumber } from '@/utils/format';

const SHOWN = 10;

/** Slow and overstocked products with how much of them sold and when they last sold: what to discount. */
export function ClearanceList() {
    const { t } = useTranslation();
    const { data } = useClearance();
    const [busy, setBusy] = useState(false);
    if (!data || data.count === 0) return null;

    const exportCsv = async () => {
        setBusy(true);
        try {
            await http.download('/clearance/export');
        } catch (e) {
            shopify.toast.show(e instanceof ApiError ? errorMessage(e) : t('errors.exportFailed'), { isError: true });
        } finally {
            setBusy(false);
        }
    };

    return (
        <s-section heading={t('clearance.heading')}>
            <s-stack gap="base">
                <s-paragraph>{t('clearance.intro', { count: data.days })}</s-paragraph>
                <s-table>
                    <s-table-header-row>
                        <s-table-header listSlot="primary">{t('table.product')}</s-table-header>
                        <s-table-header format="numeric">{t('table.inStock')}</s-table-header>
                        <s-table-header format="numeric">{t('clearance.sold', { count: data.days })}</s-table-header>
                        <s-table-header format="numeric">{t('clearance.sellThrough')}</s-table-header>
                        <s-table-header>{t('clearance.lastSale')}</s-table-header>
                        <s-table-header format="currency">{t('slow.value')}</s-table-header>
                    </s-table-header-row>
                    <s-table-body>
                        {data.items.slice(0, SHOWN).map((row) => (
                            <s-table-row key={row.variant_id}>
                                <s-table-cell>
                                    <s-link href={`/products/${row.variant_id}`}>{row.name}</s-link>
                                </s-table-cell>
                                <s-table-cell>{formatNumber(row.stock, 0)}</s-table-cell>
                                <s-table-cell>{formatNumber(row.sold, 0)}</s-table-cell>
                                <s-table-cell>{row.sell_through === null ? '—' : `${formatNumber(row.sell_through * 100, 0)}%`}</s-table-cell>
                                <s-table-cell>
                                    {row.last_sold_on === null
                                        ? t('clearance.never')
                                        : t('clearance.daysAgo', { count: row.days_since_sale ?? 0, date: formatDate(row.last_sold_on) })}
                                </s-table-cell>
                                <s-table-cell>{row.value === null ? '—' : formatMoney(row.value, data.currency)}</s-table-cell>
                            </s-table-row>
                        ))}
                    </s-table-body>
                </s-table>
                <s-stack direction="inline" gap="small-200" alignItems="center">
                    <s-button icon="export" loading={busy || undefined} onClick={exportCsv}>{t('clearance.export')}</s-button>
                    {data.count > SHOWN && <s-text color="subdued">{t('clearance.more', { count: data.count - SHOWN })}</s-text>}
                </s-stack>
            </s-stack>
        </s-section>
    );
}
