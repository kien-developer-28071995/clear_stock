import { useTranslation } from 'react-i18next';
import { useSizeRuns } from '@/features/reports/hooks/useReports';
import { formatNumber } from '@/utils/format';

/** Products where a best-selling variant (size, colour) is short while other variants sit in stock. */
export function SizeRuns() {
    const { t } = useTranslation();
    const { data } = useSizeRuns();
    if (!data || data.length === 0) return null;

    return (
        <s-section heading={t('sizeRuns.heading')}>
            <s-stack gap="base">
                <s-paragraph>{t('sizeRuns.intro')}</s-paragraph>
                {data.map((run) => (
                    <s-stack key={run.product_id} gap="small-200">
                        <s-text type="strong">{run.product}</s-text>
                        <s-stack direction="inline" gap="small-200">
                            {run.variants.map((v) => (
                                <s-link key={v.variant_id} href={`/products/${v.variant_id}`}>
                                    <s-badge tone={v.state === 'short' ? 'critical' : v.state === 'sitting' ? 'warning' : 'neutral'}>
                                        {t(`sizeRuns.${v.state}`, {
                                            title: v.title ?? '—',
                                            share: formatNumber(v.share * 100, 0),
                                            stock: formatNumber(v.stock, 0),
                                        })}
                                    </s-badge>
                                </s-link>
                            ))}
                        </s-stack>
                    </s-stack>
                ))}
            </s-stack>
        </s-section>
    );
}
