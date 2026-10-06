import { SectionTabs } from '@/components/layout/SectionTabs';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MissingCostNote } from '@/components/ui/MissingCostNote';
import { useSearchParams } from 'react-router';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { UpgradePrompt } from '@/components/ui/UpgradePrompt';
import { useEntitlements, useFeature } from '@/hooks/useEntitlements';
import { useFacets } from '@/features/forecasts/hooks/useForecasts';
import { useSuppliers } from '@/features/settings/hooks/useSettings';
import { useWhatIf } from '@/features/whatif/hooks/useWhatIf';
import { whatIfApi } from '@/features/whatif/api/whatIfApi';
import { ApiError, errorMessage } from '@/lib/http';
import type { Horizon, WhatIfItem, WhatIfParams, WhatIfSide, WhatIfTotals } from '@/features/whatif/types';
import { formatDate, formatMoney, formatNumber } from '@/utils/format';
import { NO_VALUE, fromOption, optionValue } from '@/utils/select';

const PRESETS = [-20, 10, 20, 50, 100];
const HORIZONS: Horizon[] = [0, 14, 30];
const MIN = -90;
const MAX = 500;

const clamp = (n: number) => Math.min(MAX, Math.max(MIN, Math.round(n)));
const signed = (n: number) => `${n > 0 ? '+' : ''}${formatNumber(n, 0)}%`;

/** "now → scenario", or just the value when nothing changes. */
function Change({ now, scenario }: { now: string; scenario: string }) {
    if (now === scenario) return <s-text>{scenario}</s-text>;
    return (
        <s-text>
            <s-text color="subdued">{now}</s-text> → <s-text type="strong">{scenario}</s-text>
        </s-text>
    );
}

function Metric({ label, now, scenario }: { label: string; now: string; scenario: string }) {
    return (
        <s-stack gap="small-100">
            <s-text color="subdued">{label}</s-text>
            <Change now={now} scenario={scenario} />
        </s-stack>
    );
}

/** Growth what-if (Starter and up): every product's sales rate x (1 + growth), same reorder maths, nothing saved. */
export function WhatIfPage() {
    const { t } = useTranslation();
    if (!useEntitlements().what_if) {
        return (
            <s-page heading={t('nav.planning')}><SectionTabs group="planning" />
                <UpgradePrompt id="what-if" plan="starter">{t('whatIf.locked')}</UpgradePrompt>
                <s-section>
                    <s-paragraph>{t('whatIf.intro')}</s-paragraph>
                </s-section>
            </s-page>
        );
    }

    return <WhatIfView />;
}

function WhatIfView() {
    const { t } = useTranslation();
    const abcExists = useFeature('abc');
    const [search, setSearch] = useSearchParams();
    const params: WhatIfParams = {
        growth: clamp(Number(search.get('growth') ?? 20) || 0),
        horizon: (HORIZONS.includes(Number(search.get('horizon')) as Horizon) && search.get('horizon') !== null ? Number(search.get('horizon')) : 30) as Horizon,
        supplier_id: search.get('supplier_id') ? Number(search.get('supplier_id')) : '',
        vendor: search.get('vendor') ?? '',
        abc: (search.get('abc') ?? '') as WhatIfParams['abc'],
    };
    const [growthInput, setGrowthInput] = useState(String(params.growth));
    const { data, error, isPending, isFetching, refetch } = useWhatIf(params);
    const suppliers = useSuppliers().data ?? [];
    const vendors = useFacets().data?.vendors ?? [];

    const poAllowed = useEntitlements().purchase_orders;
    const poExists = useFeature('purchase_orders');
    const [exporting, setExporting] = useState(false);
    const exportCsv = async () => {
        setExporting(true);
        try {
            await whatIfApi.export(params);
        } catch (e) {
            shopify.toast.show(e instanceof ApiError ? errorMessage(e) : t('errors.exportFailed'), { isError: true });
        } finally {
            setExporting(false);
        }
    };

    const update = (patch: Partial<WhatIfParams>) => {
        const next = { ...params, ...patch };
        setSearch(Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v !== undefined).map(([k, v]) => [k, String(v)])), { replace: true });
    };

    // Typing in the growth field: apply after a short pause.
    useEffect(() => {
        const n = Number(growthInput);
        if (growthInput === '' || Number.isNaN(n) || clamp(n) === params.growth) return;
        const timer = setTimeout(() => update({ growth: clamp(n) }), 400);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [growthInput]);

    const setGrowth = (n: number) => {
        setGrowthInput(String(n));
        update({ growth: n });
    };

    const money = (v: number) => formatMoney(v, data?.currency ?? null);
    const qty = (s: WhatIfSide) => (s.order_qty > 0 ? formatNumber(s.order_qty, 0) : '—');
    const date = (s: WhatIfSide) => (s.order_qty > 0 ? formatDate(s.order_date) : t('whatIf.notDue'));
    // No product with a cost: 0 would be misleading.
    const totalsCost = (x: WhatIfTotals) => (x.cost === 0 && x.missing_cost > 0 ? '—' : money(x.cost));

    return (
        <s-page heading={t('nav.planning')}><SectionTabs group="planning" />
            {poAllowed && poExists && (data?.totals.scenario.products ?? 0) > 0 && (
                <s-button slot="secondary-actions" icon="export" loading={exporting || undefined} onClick={exportCsv}>
                    {t('whatIf.export')}
                </s-button>
            )}
            <s-section heading={t('whatIf.scenario')}>
                <s-stack gap="base">
                    <s-paragraph>{t('whatIf.intro')}</s-paragraph>
                    <s-query-container><s-grid gridTemplateColumns="@container (inline-size > 600px) 200px 1fr, 1fr" gap="base" alignItems="end">
                        <s-number-field
                            label={t('whatIf.growth')}
                            suffix="%"
                            min={MIN}
                            max={MAX}
                            step={5}
                            value={growthInput}
                            onInput={(e) => setGrowthInput(e.currentTarget.value)}
                        />
                        <s-stack direction="inline" gap="small-200">
                            {PRESETS.map((p) => (
                                <s-button key={p} variant={p === params.growth ? 'primary' : 'secondary'} onClick={() => setGrowth(p)}>
                                    {signed(p)}
                                </s-button>
                            ))}
                        </s-stack>
                    </s-grid></s-query-container>
                    <s-query-container><s-grid gridTemplateColumns="@container (inline-size > 600px) 1fr 1fr 1fr 1fr, 1fr 1fr" gap="base">
                        <s-select label={t('whatIf.horizon')} value={String(params.horizon)} onChange={(e) => update({ horizon: Number(e.currentTarget.value) as Horizon })}>
                            {HORIZONS.map((h) => (
                                <s-option key={h} value={String(h)}>{t(`whatIf.horizons.h${h}`)}</s-option>
                            ))}
                        </s-select>
                        {suppliers.length > 0 && (
                            <s-select
                                label={t('table.supplier')}
                                value={optionValue(params.supplier_id)}
                                onChange={(e) => update({ supplier_id: fromOption(e.currentTarget.value) ? Number(e.currentTarget.value) : '' })}
                            >
                                <s-option value={NO_VALUE}>{t('whatIf.allSuppliers')}</s-option>
                                {suppliers.map((s) => (
                                    <s-option key={s.id} value={String(s.id)}>{s.name}</s-option>
                                ))}
                            </s-select>
                        )}
                        {vendors.length > 1 && (
                            <s-select label={t('products.vendor')} value={optionValue(params.vendor)} onChange={(e) => update({ vendor: fromOption(e.currentTarget.value) })}>
                                <s-option value={NO_VALUE}>{t('products.allVendors')}</s-option>
                                {vendors.map((v) => (
                                    <s-option key={v} value={v}>{v}</s-option>
                                ))}
                            </s-select>
                        )}
                        {abcExists && (<s-select
                            label={t('abc.filter')}
                            value={optionValue(params.abc)}
                            onChange={(e) => update({ abc: fromOption(e.currentTarget.value) as WhatIfParams['abc'] })}
                        >
                            <s-option value={NO_VALUE}>{t('abc.allClasses')}</s-option>
                            {(['A', 'B', 'C'] as const).map((c) => (
                                <s-option key={c} value={c}>{t(`abc.option${c}`)}</s-option>
                            ))}
                        </s-select>)}
                    </s-grid></s-query-container>
                </s-stack>
            </s-section>

            {error && <ErrorBanner error={error} onRetry={() => refetch()} />}

            {data && (
                <s-section heading={t('whatIf.resultHeading', { growth: signed(data.growth_percent), factor: formatNumber(data.factor, 2) })}>
                    <s-stack gap="base">
                        <s-query-container><s-grid gridTemplateColumns="@container (inline-size > 600px) 1fr 1fr 1fr 1fr, 1fr 1fr" gap="base">
                            <Metric
                                label={t(`whatIf.productsToOrder.h${data.horizon_days}`)}
                                now={formatNumber(data.totals.now.products, 0)}
                                scenario={formatNumber(data.totals.scenario.products, 0)}
                            />
                            <Metric label={t('whatIf.units')} now={formatNumber(data.totals.now.units, 0)} scenario={formatNumber(data.totals.scenario.units, 0)} />
                            <Metric label={t('whatIf.cost')} now={totalsCost(data.totals.now)} scenario={totalsCost(data.totals.scenario)} />
                            <Metric
                                label={t('whatIf.stockoutRisk')}
                                now={formatNumber(data.totals.now.stockout_risk, 0)}
                                scenario={formatNumber(data.totals.scenario.stockout_risk, 0)}
                            />
                        </s-grid></s-query-container>
                        <s-text color="subdued">{t('whatIf.legend')}</s-text>
                        <MissingCostNote count={data.totals.scenario.missing_cost} text={t('whatIf.missingCost', { count: data.totals.scenario.missing_cost })} />
                    </s-stack>
                </s-section>
            )}

            {data && data.items.length === 0 && (
                <s-section>
                    <s-paragraph>{t(`whatIf.nothingDue.h${data.horizon_days}`)}</s-paragraph>
                </s-section>
            )}

            {data && data.items.length > 0 && (
                <s-section padding="none">
                    <s-table loading={(isPending || isFetching) || undefined}>
                        <s-table-header-row>
                            <s-table-header listSlot="primary">{t('table.product')}</s-table-header>
                            <s-table-header format="numeric">{t('table.perDay')}</s-table-header>
                            <s-table-header>{t('table.orderBy')}</s-table-header>
                            <s-table-header format="numeric">{t('whatIf.orderQty')}</s-table-header>
                            <s-table-header format="currency">{t('whatIf.lineCost')}</s-table-header>
                        </s-table-header-row>
                        <s-table-body>
                            {data.items.map((item: WhatIfItem) => (
                                <s-table-row key={item.variant_id}>
                                    <s-table-cell>
                                        <s-stack gap="small-100">
                                            <s-link href={`/products/${item.variant_id}`}>{item.name}</s-link>
                                            {(item.sku || item.supplier) && <s-text color="subdued">{[item.sku, item.supplier].filter(Boolean).join(' · ')}</s-text>}
                                            {item.scenario.stockout_risk && (
                                                <s-badge tone="critical">{t('whatIf.riskBadge', { count: item.lead_time_days })}</s-badge>
                                            )}
                                        </s-stack>
                                    </s-table-cell>
                                    <s-table-cell>
                                        <Change now={formatNumber(item.now.avg, 2)} scenario={formatNumber(item.scenario.avg, 2)} />
                                    </s-table-cell>
                                    <s-table-cell>
                                        <Change now={date(item.now)} scenario={date(item.scenario)} />
                                    </s-table-cell>
                                    <s-table-cell>
                                        <Change now={qty(item.now)} scenario={qty(item.scenario)} />
                                    </s-table-cell>
                                    <s-table-cell>
                                        {item.unit_cost === null || item.scenario.order_qty === 0 ? '—' : money(item.unit_cost * item.scenario.order_qty)}
                                    </s-table-cell>
                                </s-table-row>
                            ))}
                        </s-table-body>
                    </s-table>
                </s-section>
            )}
            {data && data.items_total > data.items.length && (
                <s-text color="subdued">{t('whatIf.truncated', { shown: data.items.length, count: data.items_total })}</s-text>
            )}
        </s-page>
    );
}
