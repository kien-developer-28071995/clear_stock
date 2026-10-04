import { SectionTabs } from '@/components/layout/SectionTabs';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { MissingCostNote } from '@/components/ui/MissingCostNote';
import { SaveBar } from '@/components/ui/SaveBar';
import { UpgradePrompt } from '@/components/ui/UpgradePrompt';
import { fieldError } from '@/lib/http';
import { useModal } from '@/hooks/useModal';
import { useEntitlements } from '@/hooks/useEntitlements';
import { ExportPurchaseOrderButton } from '@/features/forecasts/components/ExportPurchaseOrderButton';
import { MarkOrderedModal } from '@/features/orders/components/MarkOrderedModal';
import { useBudget, useSetBudget } from '@/features/budget/hooks/useBudget';
import type { BudgetReason } from '@/features/budget/types';
import { formatDate, formatMoney, formatNumber } from '@/utils/format';

const TONE: Record<BudgetReason, 'critical' | 'warning' | 'neutral'> = {
    out_of_stock: 'critical',
    runs_out_before_delivery: 'warning',
    reorder_point: 'neutral',
};

/** Monthly purchasing budget (Starter): everything due now, most important first, filling the budget left. */
export function BudgetPage() {
    const { t } = useTranslation();
    if (!useEntitlements().order_budget) {
        return (
            <s-page heading={t('nav.planning')}><SectionTabs group="planning" />
                <UpgradePrompt id="order-budget" plan="starter">{t('budget.locked')}</UpgradePrompt>
                <s-section><s-paragraph>{t('budget.intro')}</s-paragraph></s-section>
            </s-page>
        );
    }

    return <BudgetView />;
}

function BudgetView() {
    const { t } = useTranslation();
    const { data, error, refetch, isFetching } = useBudget();
    const save = useSetBudget();
    const [value, setValue] = useState('');
    const markModal = useModal();
    const saved = data?.budget == null ? '' : String(data.budget);
    useEffect(() => setValue(saved), [saved]);
    const inBudget = useMemo(() => (data?.items ?? []).filter((i) => i.in_budget), [data]);

    if (!data) return error ? <s-page heading={t('nav.planning')}><SectionTabs group="planning" /><ErrorBanner error={error} onRetry={() => refetch()} /></s-page> : <LoadingPage heading={t('nav.planning')} group="planning" />;

    const money = (v: number | null) => (v === null ? '—' : formatMoney(v, data.currency));
    const submit = () => save.mutate(value.trim() === '' ? null : Number(value.replace(',', '.')), { onSuccess: () => shopify.toast.show(t('common.saved')) });

    return (
        <s-page heading={t('nav.planning')}><SectionTabs group="planning" />
            <SaveBar id="budget-save-bar" dirty={value !== saved} saving={save.isPending} onSave={submit} onDiscard={() => setValue(saved)} />
            <s-section>
                <s-stack gap="base">
                    <s-paragraph>{t('budget.intro')}</s-paragraph>
                    <s-query-container><s-grid gridTemplateColumns="@container (inline-size > 600px) 1fr 1fr 1fr, 1fr" gap="base" alignItems="start">
                        <s-number-field
                            label={t('budget.monthly')}
                            min={0}
                            step={100}
                            placeholder={t('budget.none')}
                            prefix={data.currency ?? undefined}
                            value={value}
                            error={fieldError(save.error, 'budget')}
                            onInput={(e) => setValue(e.currentTarget.value)}
                        />
                        <s-stack gap="small-100">
                            <s-text color="subdued">{t('budget.spent')}</s-text>
                            <s-heading>{money(data.spent.cost)}</s-heading>
                            <s-text color="subdued">{t('budget.spentHint', { count: data.spent.orders })}</s-text>
                        </s-stack>
                        <s-stack gap="small-100">
                            <s-text color="subdued">{t('budget.remaining')}</s-text>
                            <s-heading>{money(data.remaining)}</s-heading>
                        </s-stack>
                    </s-grid></s-query-container>
                    <s-text color="subdued">{t('budget.how')}</s-text>
                </s-stack>
            </s-section>

            {data.items.length === 0 ? (
                <s-section><s-paragraph>{t('budget.nothingDue')}</s-paragraph></s-section>
            ) : (
                <s-section heading={t('budget.dueHeading')} padding="none">
                    <s-box padding="none base base">
                        <s-stack gap="small-200">
                            <s-text>
                                {data.budget === null
                                    ? t('budget.summaryNoBudget', { count: data.totals.products, cost: money(data.totals.cost) })
                                    : t('budget.summary', { count: data.totals.in_budget, cost: money(data.totals.in_budget_cost), waiting: data.totals.waiting, waitingCost: money(data.totals.waiting_cost) })}
                            </s-text>
                            <MissingCostNote count={data.totals.missing_cost} />
                            <s-stack direction="inline" gap="small-200">
                                <s-button disabled={inBudget.length === 0 || undefined} onClick={() => markModal.open()}>{t('orders.markSelected')}</s-button>
                                <ExportPurchaseOrderButton variantIds={inBudget.map((i) => i.variant_id)} label={t('budget.export')} />
                            </s-stack>
                        </s-stack>
                    </s-box>
                    <s-table loading={isFetching || undefined}>
                        <s-table-header-row>
                            <s-table-header format="numeric">#</s-table-header>
                            <s-table-header listSlot="primary">{t('table.product')}</s-table-header>
                            <s-table-header>{t('budget.why')}</s-table-header>
                            <s-table-header format="numeric">{t('table.suggestedOrder')}</s-table-header>
                            <s-table-header format="currency">{t('purchasePlan.cost')}</s-table-header>
                            <s-table-header>{t('budget.status')}</s-table-header>
                        </s-table-header-row>
                        <s-table-body>
                            {data.items.map((i) => (
                                <s-table-row key={i.variant_id}>
                                    <s-table-cell>{i.priority}</s-table-cell>
                                    <s-table-cell>
                                        <s-stack gap="small-100">
                                            <s-link href={`/products/${i.variant_id}`}>{i.name}</s-link>
                                            <s-text color="subdued">{[i.sku, i.supplier, i.abc_class && t('budget.class', { abc: i.abc_class })].filter(Boolean).join(' · ')}</s-text>
                                        </s-stack>
                                    </s-table-cell>
                                    <s-table-cell>
                                        <s-badge tone={TONE[i.reason]}>{t(`budget.reason.${i.reason}`, { date: formatDate(i.stockout_date) })}</s-badge>
                                    </s-table-cell>
                                    <s-table-cell>{formatNumber(i.quantity, 0)}</s-table-cell>
                                    <s-table-cell>{money(i.cost)}</s-table-cell>
                                    <s-table-cell>
                                        {i.cost === null ? (
                                            <s-badge tone="neutral">{t('budget.noCost')}</s-badge>
                                        ) : (
                                            <s-badge tone={i.in_budget ? 'success' : 'warning'}>{i.in_budget ? t('budget.inBudget') : t('budget.waiting')}</s-badge>
                                        )}
                                    </s-table-cell>
                                </s-table-row>
                            ))}
                        </s-table-body>
                    </s-table>
                    {data.items_total > data.items.length && (
                        <s-box padding="base">
                            <s-text color="subdued">{t('whatIf.truncated', { shown: data.items.length, count: data.items_total })}</s-text>
                        </s-box>
                    )}
                </s-section>
            )}
            <MarkOrderedModal id="mark-ordered-budget" modalRef={markModal.ref} items={inBudget.map((i) => ({ variant_id: i.variant_id, name: i.name, quantity: i.quantity }))} />
        </s-page>
    );
}
