import { useTranslation } from 'react-i18next';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { useDataHealth } from '@/features/reports/hooks/useReports';
import type { HealthCode } from '@/features/reports/types';
import { formatNumber } from '@/utils/format';

/** Where each finding is fixed: in the app, or in the Shopify admin (no link: explained in the text). */
const FIX_LINK = {
    missing_cost: { href: '/costs', label: 'health.findings.missing_cost.fix' },
    no_supplier: { href: '/suppliers', label: 'health.findings.no_supplier.fix' },
    default_lead_time: { href: '/suppliers', label: 'health.findings.default_lead_time.fix' },
} as const satisfies Partial<Record<HealthCode, { href: string; label: string }>>;

/** Product data problems that make forecasts or money figures wrong, with examples and where to fix them. */
export function DataHealthPage() {
    const { t } = useTranslation();
    const { data, isPending, error, refetch } = useDataHealth();

    if (isPending) return <LoadingPage heading={t('health.heading')} />;

    return (
        <s-page heading={t('health.heading')} inlineSize="small">
            <s-link slot="breadcrumb-actions" href="/settings">{t('nav.settings')}</s-link>
            {error && <ErrorBanner error={error} onRetry={() => refetch()} />}
            {data && (
                <>
                    <s-section>
                        <s-paragraph>
                            {data.findings.length === 0
                                ? t('health.allGood', { count: data.checked, products: formatNumber(data.checked, 0) })
                                : t('health.intro', { count: data.checked, products: formatNumber(data.checked, 0) })}
                        </s-paragraph>
                    </s-section>
                    {data.findings.map((f) => {
                        const fix = f.code in FIX_LINK ? FIX_LINK[f.code as keyof typeof FIX_LINK] : null;
                        return (
                        <s-section key={f.code}>
                            <s-stack gap="small-200">
                                <s-stack direction="inline" gap="small-200" alignItems="center">
                                    <s-badge tone={f.severity === 'warning' ? 'warning' : 'info'}>{formatNumber(f.count, 0)}</s-badge>
                                    <s-text type="strong">{t(`health.findings.${f.code}.title`, { count: f.count })}</s-text>
                                </s-stack>
                                <s-paragraph>{t(`health.findings.${f.code}.body`, { days: data.default_lead_time_days })}</s-paragraph>
                                <s-stack gap="small-100">
                                    {f.sample.map((v) => (
                                        <s-stack key={v.variant_id} direction="inline" gap="small-200">
                                            <s-link href={`/products/${v.variant_id}`}>{v.name}</s-link>
                                            {v.sku && <s-text color="subdued">{v.sku}</s-text>}
                                        </s-stack>
                                    ))}
                                    {f.count > f.sample.length && <s-text color="subdued">{t('health.more', { count: f.count - f.sample.length })}</s-text>}
                                </s-stack>
                                {fix && <s-link href={fix.href}>{t(fix.label)}</s-link>}
                            </s-stack>
                        </s-section>
                        );
                    })}
                </>
            )}
        </s-page>
    );
}
