import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MissingCostNote } from '@/components/ui/MissingCostNote';
import { useSearchParams } from 'react-router';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { UpgradePrompt } from '@/components/ui/UpgradePrompt';
import { useEntitlements, useFeature } from '@/hooks/useEntitlements';
import { ApiError, errorMessage } from '@/lib/http';
import { purchasePlanApi } from '@/features/purchasePlan/api/purchasePlanApi';
import { useFacets } from '@/features/forecasts/hooks/useForecasts';
import { useSuppliers } from '@/features/settings/hooks/useSettings';
import { usePurchasePlan } from '@/features/purchasePlan/hooks/usePurchasePlan';
import { WeeklySpendChart } from '@/features/purchasePlan/components/WeeklySpendChart';
import type { PurchasePlanParams, Weeks } from '@/features/purchasePlan/types';
import { formatDate, formatDateWithWeekday, formatMoney, formatMonth, formatNumber } from '@/utils/format';
import { NO_VALUE, fromOption, optionValue } from '@/utils/select';

const WEEKS: Weeks[] = [4, 8, 12];

/** Purchase plan (Starter and up): orders and spend week by week at the current sales rates. */
export function PurchasePlanPage() {
    const { t } = useTranslation();
    if (!useEntitlements().purchase_plan) {
        return (
            <s-page heading={t('nav.purchasePlan')}>
                <UpgradePrompt id="purchase-plan" plan="starter">{t('purchasePlan.locked')}</UpgradePrompt>
                <s-section>
                    <s-paragraph>{t('purchasePlan.intro')}</s-paragraph>
                </s-section>
            </s-page>
        );
    }

    return <PurchasePlanView />;
}

function PurchasePlanView() {
    const { t } = useTranslation();
    const [search, setSearch] = useSearchParams();
    const weeksParam = Number(search.get('weeks'));
    const params: PurchasePlanParams = {
        weeks: (WEEKS.includes(weeksParam as Weeks) ? weeksParam : 12) as Weeks,
        supplier_id: search.get('supplier_id') ? Number(search.get('supplier_id')) : '',
        vendor: search.get('vendor') ?? '',
    };
    const { data, error, isFetching, refetch } = usePurchasePlan(params);
    const suppliers = useSuppliers().data ?? [];
    const vendors = useFacets().data?.vendors ?? [];

    const poAllowed = useEntitlements().purchase_orders;
    const poExists = useFeature('purchase_orders');
    const canExport = poAllowed && poExists;
    const [exporting, setExporting] = useState(false);
    const exportCsv = async () => {
        setExporting(true);
        try {
            await purchasePlanApi.export(params);
        } catch (e) {
            shopify.toast.show(e instanceof ApiError ? errorMessage(e) : t('errors.exportFailed'), { isError: true });
        } finally {
            setExporting(false);
        }
    };

    const update = (patch: Partial<PurchasePlanParams>) => {
        const next = { ...params, ...patch };
        setSearch(Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v !== undefined).map(([k, v]) => [k, String(v)])), { replace: true });
    };

    if (!data) {
        return error ? (
            <s-page heading={t('nav.purchasePlan')}>
                <ErrorBanner error={error} onRetry={() => refetch()} />
            </s-page>
        ) : (
            <LoadingPage heading={t('nav.purchasePlan')} />
        );
    }

    const money = (v: number | null, missing = 0) => (v === null || (v === 0 && missing > 0) ? '—' : formatMoney(v, data?.currency ?? null));

    return (
        <s-page heading={t('nav.purchasePlan')}>
            <s-link slot="breadcrumb-actions" href="/reorder">{t('nav.reorder')}</s-link>
            {canExport && data.totals.orders > 0 && (
                <s-button slot="secondary-actions" icon="export" loading={exporting || undefined} onClick={exportCsv}>
                    {t('purchasePlan.export')}
                </s-button>
            )}
            <s-section>
                <s-stack gap="base">
                    <s-paragraph>{t('purchasePlan.intro')}</s-paragraph>
                    <s-grid gridTemplateColumns="@container (inline-size > 600px) 1fr 1fr 1fr, 1fr" gap="base">
                        <s-select label={t('purchasePlan.period')} value={String(params.weeks)} onChange={(e) => update({ weeks: Number(e.currentTarget.value) as Weeks })}>
                            {WEEKS.map((w) => (
                                <s-option key={w} value={String(w)}>{t('purchasePlan.weeks', { count: w })}</s-option>
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
                    </s-grid>
                </s-stack>
            </s-section>

            {error && <ErrorBanner error={error} onRetry={() => refetch()} />}

            {data && data.totals.orders === 0 && (
                <s-section>
                    <s-paragraph>{t('purchasePlan.nothing', { count: data.weeks })}</s-paragraph>
                </s-section>
            )}

            {data && data.totals.orders > 0 && (
                <>
                    <s-section heading={t('purchasePlan.summaryHeading', { from: formatDate(data.today), to: formatDate(data.until) })}>
                        <s-stack gap="base">
                            <s-grid gridTemplateColumns="@container (inline-size > 600px) 1fr 1fr 1fr 1fr, 1fr 1fr" gap="base">
                                <s-stack gap="small-100">
                                    <s-text color="subdued">{t('purchasePlan.totalCost')}</s-text>
                                    <s-heading>{money(data.totals.cost, data.totals.missing_cost)}</s-heading>
                                </s-stack>
                                <s-stack gap="small-100">
                                    <s-text color="subdued">{t('purchasePlan.orders')}</s-text>
                                    <s-heading>{formatNumber(data.totals.orders, 0)}</s-heading>
                                </s-stack>
                                <s-stack gap="small-100">
                                    <s-text color="subdued">{t('purchasePlan.units')}</s-text>
                                    <s-heading>{formatNumber(data.totals.units, 0)}</s-heading>
                                </s-stack>
                                <s-stack gap="small-100">
                                    <s-text color="subdued">{t('purchasePlan.products')}</s-text>
                                    <s-heading>{formatNumber(data.totals.products, 0)}</s-heading>
                                </s-stack>
                            </s-grid>
                            <WeeklySpendChart weeks={data.by_week} currency={data.currency} />
                            <s-text color="subdued">{t('purchasePlan.how')}</s-text>
                            <MissingCostNote count={data.totals.missing_cost} text={t('whatIf.missingCost', { count: data.totals.missing_cost })} />
                        </s-stack>
                    </s-section>

                    {/* Spend per month against the monthly budget. */}
                    {data.budget !== null && (
                        <s-section heading={t('purchasePlan.byMonth')}>
                            <s-stack gap="small-200">
                                {data.by_month.map((m) => (
                                    <s-stack key={m.month} direction="inline" gap="small-200" alignItems="center">
                                        <s-text type="strong">{formatMonth(m.month)}</s-text>
                                        <s-text>{t('purchasePlan.monthVsBudget', { cost: money(m.cost, m.missing_cost), budget: money(data.budget) })}</s-text>
                                        {m.cost > (data.budget ?? 0) && <s-badge tone="warning">{t('purchasePlan.overBudget', { amount: money(m.cost - (data.budget ?? 0)) })}</s-badge>}
                                    </s-stack>
                                ))}
                                <s-link href="/budget">{t('purchasePlan.budgetLink')}</s-link>
                            </s-stack>
                        </s-section>
                    )}

                    {/* Order calendar: what to order from whom, day by day. */}
                    <s-section heading={t('purchasePlan.calendar')} padding="none">
                        <s-table loading={isFetching || undefined}>
                            <s-table-header-row>
                                <s-table-header listSlot="primary">{t('purchasePlan.orderDay')}</s-table-header>
                                <s-table-header>{t('table.supplier')}</s-table-header>
                                <s-table-header format="numeric">{t('purchasePlan.products')}</s-table-header>
                                <s-table-header format="numeric">{t('purchasePlan.units')}</s-table-header>
                                <s-table-header format="currency">{t('purchasePlan.cost')}</s-table-header>
                            </s-table-header-row>
                            <s-table-body>
                                {data.calendar.map((c) => (
                                    <s-table-row key={`${c.date}-${c.supplier_id ?? 0}`}>
                                        <s-table-cell>{c.date === data.today ? t('purchasePlan.today') : formatDateWithWeekday(c.date)}</s-table-cell>
                                        <s-table-cell>{c.supplier ?? t('purchasePlan.noSupplier')}</s-table-cell>
                                        <s-table-cell>{formatNumber(c.products, 0)}</s-table-cell>
                                        <s-table-cell>{formatNumber(c.units, 0)}</s-table-cell>
                                        <s-table-cell>{money(c.cost, c.missing_cost)}</s-table-cell>
                                    </s-table-row>
                                ))}
                            </s-table-body>
                        </s-table>
                    </s-section>

                    {data.by_supplier.length > 1 && (
                        <s-section heading={t('purchasePlan.bySupplier')} padding="none">
                            <s-table>
                                <s-table-header-row>
                                    <s-table-header listSlot="primary">{t('table.supplier')}</s-table-header>
                                    <s-table-header>{t('purchasePlan.firstOrder')}</s-table-header>
                                    <s-table-header format="numeric">{t('purchasePlan.orders')}</s-table-header>
                                    <s-table-header format="numeric">{t('purchasePlan.units')}</s-table-header>
                                    <s-table-header format="currency">{t('purchasePlan.cost')}</s-table-header>
                                </s-table-header-row>
                                <s-table-body>
                                    {data.by_supplier.map((s) => (
                                        <s-table-row key={s.supplier_id ?? 0}>
                                            <s-table-cell>{s.name ?? t('purchasePlan.noSupplier')}</s-table-cell>
                                            <s-table-cell>{formatDate(s.first_order)}</s-table-cell>
                                            <s-table-cell>{formatNumber(s.orders, 0)}</s-table-cell>
                                            <s-table-cell>{formatNumber(s.units, 0)}</s-table-cell>
                                            <s-table-cell>{money(s.cost, s.missing_cost)}</s-table-cell>
                                        </s-table-row>
                                    ))}
                                </s-table-body>
                            </s-table>
                        </s-section>
                    )}

                    <s-section heading={t('purchasePlan.byProduct')} padding="none">
                        <s-table loading={isFetching || undefined}>
                            <s-table-header-row>
                                <s-table-header listSlot="primary">{t('table.product')}</s-table-header>
                                <s-table-header format="numeric">{t('table.perDay')}</s-table-header>
                                <s-table-header>{t('purchasePlan.orderDates')}</s-table-header>
                                <s-table-header format="numeric">{t('purchasePlan.units')}</s-table-header>
                                <s-table-header format="currency">{t('purchasePlan.cost')}</s-table-header>
                            </s-table-header-row>
                            <s-table-body>
                                {data.items.map((item) => (
                                    <s-table-row key={item.variant_id}>
                                        <s-table-cell>
                                            <s-stack gap="small-100">
                                                <s-link href={`/products/${item.variant_id}`}>{item.name}</s-link>
                                                {(item.sku || item.supplier) && <s-text color="subdued">{[item.sku, item.supplier].filter(Boolean).join(' · ')}</s-text>}
                                            </s-stack>
                                        </s-table-cell>
                                        <s-table-cell>{formatNumber(item.avg, 2)}</s-table-cell>
                                        <s-table-cell>{item.orders.map((o) => t('purchasePlan.orderOn', { date: formatDate(o.date), qty: formatNumber(o.qty, 0) })).join(', ')}</s-table-cell>
                                        <s-table-cell>{formatNumber(item.units, 0)}</s-table-cell>
                                        <s-table-cell>{money(item.cost)}</s-table-cell>
                                    </s-table-row>
                                ))}
                            </s-table-body>
                        </s-table>
                    </s-section>
                    {data.items_total > data.items.length && (
                        <s-text color="subdued">{t('whatIf.truncated', { shown: data.items.length, count: data.items_total })}</s-text>
                    )}
                </>
            )}
        </s-page>
    );
}
