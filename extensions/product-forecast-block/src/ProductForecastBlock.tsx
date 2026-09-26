import { render } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { api, ApiError } from '../../shared/api';
import { formatDate, formatNumber, translateCode, type Coded } from '../../shared/i18n';

type Status = 'out_of_stock' | 'reorder_now' | 'overstock' | 'slow' | 'healthy';

interface Forecast {
    status: Status;
    current_stock: number;
    incoming_stock: number;
    avg_daily_sales: number;
    days_of_cover: number | null;
    stockout_date: string | null;
    reorder_date: string | null;
    suggested_qty: number;
    confidence: 'low' | 'medium' | 'high';
    explanation_lines: Coded[];
}

interface VariantForecast {
    variant_id: number;
    title: string | null;
    sku: string | null;
    forecast: Forecast | null;
    not_forecast_reason: 'not_tracked' | 'plan_limit' | 'no_forecast' | null;
}

interface ProductForecast {
    synced: boolean;
    variants: VariantForecast[];
}

type State = { kind: 'loading' } | { kind: 'error'; message: string } | { kind: 'ready'; data: ProductForecast };

const URGENCY: Status[] = ['out_of_stock', 'reorder_now', 'overstock', 'slow', 'healthy'];
const TONE: Record<Status, 'critical' | 'warning' | 'caution' | 'neutral' | 'success'> = {
    out_of_stock: 'critical',
    reorder_now: 'warning',
    overstock: 'caution',
    slow: 'neutral',
    healthy: 'success',
};

export default async () => {
    render(<ProductForecastBlock />, document.body);
};

/** The variant to explain first: the most urgent one with a forecast. */
function mostUrgent(variants: VariantForecast[]): VariantForecast | undefined {
    return variants
        .filter((v) => v.forecast)
        .sort((a, b) => URGENCY.indexOf(a.forecast!.status) - URGENCY.indexOf(b.forecast!.status))[0];
}

function ProductForecastBlock() {
    const { i18n, data } = shopify;
    const t = i18n.translate;
    const productGid = data.selected[0]?.id ?? '';
    const [state, setState] = useState<State>({ kind: 'loading' });

    useEffect(() => {
        api<ProductForecast>(`extension/products/${productGid.split('/').pop()}`)
            .then((result) => setState({ kind: 'ready', data: result }))
            .catch((e) => setState({ kind: 'error', message: t(e instanceof ApiError && e.code === 'network_error' ? 'errors.network' : 'errors.generic') }));
    }, [productGid]);

    if (state.kind === 'loading') {
        return (
            <s-admin-block heading={t('name')}>
                <s-spinner accessibilityLabel={t('name')} />
            </s-admin-block>
        );
    }
    if (state.kind === 'error') {
        return (
            <s-admin-block heading={t('name')}>
                <s-banner tone="critical">{state.message}</s-banner>
            </s-admin-block>
        );
    }

    const { synced, variants } = state.data;
    if (!synced) {
        return (
            <s-admin-block heading={t('name')}>
                <s-text color="subdued">{t('notSynced')}</s-text>
            </s-admin-block>
        );
    }

    const explained = mostUrgent(variants);
    const showVariant = variants.some((v) => v.title);
    const status = (s: Status) => t(`status.${s}`);
    const summary = explained?.forecast
        ? explained.forecast.suggested_qty > 0
            ? t('summary', { status: status(explained.forecast.status), qty: formatNumber(i18n, explained.forecast.suggested_qty, 0), date: formatDate(i18n, explained.forecast.reorder_date) })
            : t('summaryNoOrder', { status: status(explained.forecast.status) })
        : '';

    return (
        <s-admin-block heading={t('name')} collapsedSummary={summary}>
            <s-stack gap="base">
                <s-table>
                    <s-table-header-row>
                        {showVariant && <s-table-header listSlot="primary">{t('variant')}</s-table-header>}
                        <s-table-header listSlot={showVariant ? 'inline' : 'primary'}>{t('state')}</s-table-header>
                        <s-table-header format="numeric">{t('inStock')}</s-table-header>
                        <s-table-header format="numeric">{t('perDay')}</s-table-header>
                        <s-table-header>{t('runsOut')}</s-table-header>
                        <s-table-header>{t('orderBy')}</s-table-header>
                        <s-table-header format="numeric">{t('suggested')}</s-table-header>
                    </s-table-header-row>
                    <s-table-body>
                        {variants.map((v) => {
                            const f = v.forecast;
                            return (
                                <s-table-row key={v.variant_id}>
                                    {showVariant && (
                                        <s-table-cell>
                                            <s-link href={`app:products/${v.variant_id}`}>{v.title ?? v.sku ?? '—'}</s-link>
                                        </s-table-cell>
                                    )}
                                    <s-table-cell>
                                        {f ? (
                                            <s-badge tone={TONE[f.status]}>{status(f.status)}</s-badge>
                                        ) : (
                                            <s-text color="subdued">{t(`reasons.${v.not_forecast_reason ?? 'no_forecast'}`)}</s-text>
                                        )}
                                    </s-table-cell>
                                    <s-table-cell>
                                        {f ? (
                                            <s-stack gap="small-500">
                                                <s-text>{formatNumber(i18n, f.current_stock, 0)}</s-text>
                                                {f.incoming_stock > 0 && <s-text color="subdued">{t('incoming', { qty: formatNumber(i18n, f.incoming_stock, 0) })}</s-text>}
                                            </s-stack>
                                        ) : (
                                            '—'
                                        )}
                                    </s-table-cell>
                                    <s-table-cell>{f ? formatNumber(i18n, f.avg_daily_sales, 2) : '—'}</s-table-cell>
                                    <s-table-cell>{f?.stockout_date ? formatDate(i18n, f.stockout_date) : t('never')}</s-table-cell>
                                    <s-table-cell>{f && f.avg_daily_sales > 0 ? formatDate(i18n, f.reorder_date) : '—'}</s-table-cell>
                                    <s-table-cell>{f ? formatNumber(i18n, f.suggested_qty, 0) : '—'}</s-table-cell>
                                </s-table-row>
                            );
                        })}
                    </s-table-body>
                </s-table>

                {variants.some((v) => v.not_forecast_reason === 'plan_limit') && <s-link href="app:plans">{t('seePlans')}</s-link>}

                {explained?.forecast && explained.forecast.explanation_lines.length > 0 && (
                    <s-stack gap="small-200">
                        <s-heading>{showVariant && explained.title ? t('whyFor', { name: explained.title }) : t('why')}</s-heading>
                        <s-unordered-list>
                            {explained.forecast.explanation_lines.map((line, i) => (
                                <s-list-item key={i}>{translateCode(i18n, 'explanation', line)}</s-list-item>
                            ))}
                        </s-unordered-list>
                    </s-stack>
                )}

                {explained && <s-link href={`app:products/${explained.variant_id}`}>{t('open')}</s-link>}
            </s-stack>
        </s-admin-block>
    );
}
