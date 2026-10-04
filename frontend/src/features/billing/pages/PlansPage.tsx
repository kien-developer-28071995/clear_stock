import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import type { TFunction } from 'i18next';
import { useQueryClient } from '@tanstack/react-query';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { billingApi } from '@/features/billing/api/billingApi';
import { translateCode } from '@/i18n/codes';
import { useConfirm } from '@/components/ui/ConfirmModal';
import { useBilling, useChangePlan } from '@/features/billing/hooks/useBilling';
import type { Interval, PlanInfo } from '@/features/billing/types';
import type { FeatureSwitch } from '@/features/shop/types';
import { formatMoney, formatNumber } from '@/utils/format';

/** Rows of the comparison; `feature` rows disappear when that feature is switched off app-wide. */
const FEATURES: { key: string; feature?: FeatureSwitch; label: (p: PlanInfo, t: TFunction) => string; included: (p: PlanInfo) => boolean }[] = [
    {
        key: 'skus',
        label: (p, t) => (p.limits.max_skus ? t('plans.features.bestSellers', { count: p.limits.max_skus }) : t('plans.features.unlimited')),
        included: () => true,
    },
    { key: 'forecasts', label: (_, t) => t('plans.features.forecasts'), included: () => true },
    { key: 'explanations', label: (_, t) => t('plans.features.explanations'), included: (p) => p.limits.explanations },
    { key: 'abc', feature: 'abc', label: (_, t) => t('plans.features.abc'), included: (p) => p.limits.abc },
    { key: 'spike_filter', feature: 'spike_filter', label: (_, t) => t('plans.features.spikeFilter'), included: (p) => p.limits.spike_filter },
    { key: 'lost_sales', feature: 'lost_sales', label: (_, t) => t('plans.features.lostSales'), included: (p) => p.limits.lost_sales },
    { key: 'sales_events', feature: 'sales_events', label: (_, t) => t('plans.features.salesEvents'), included: (p) => p.limits.sales_events },
    { key: 'accuracy', feature: 'accuracy', label: (_, t) => t('plans.features.accuracy'), included: (p) => p.limits.accuracy },
    { key: 'bundles', label: (_, t) => t('plans.features.bundles'), included: (p) => p.limits.bundles },
    { key: 'alerts', label: (_, t) => t('plans.features.alerts'), included: (p) => p.limits.alerts },
    { key: 'purchase_orders', feature: 'purchase_orders', label: (_, t) => t('plans.features.purchaseOrders'), included: (p) => p.limits.purchase_orders },
    { key: 'supplier_emails', feature: 'supplier_emails', label: (_, t) => t('plans.features.supplierEmails'), included: (p) => p.limits.supplier_emails },
    { key: 'reference_products', feature: 'reference_products', label: (_, t) => t('plans.features.referenceProducts'), included: (p) => p.limits.reference_products },
    { key: 'what_if', feature: 'what_if', label: (_, t) => t('plans.features.whatIf'), included: (p) => p.limits.what_if },
    { key: 'purchase_plan', feature: 'purchase_plan', label: (_, t) => t('plans.features.purchasePlan'), included: (p) => p.limits.purchase_plan },
    { key: 'order_budget', feature: 'order_budget', label: (_, t) => t('plans.features.orderBudget'), included: (p) => p.limits.order_budget },
    { key: 'locations', feature: 'locations', label: (_, t) => t('plans.features.locations'), included: (p) => p.limits.locations },
    { key: 'transfers', feature: 'transfers', label: (_, t) => t('plans.features.transfers'), included: (p) => p.limits.transfers },
    { key: 'supplier_auto_email', feature: 'supplier_emails', label: (_, t) => t('plans.features.supplierAutoEmail'), included: (p) => p.limits.supplier_auto_email },
    { key: 'realtime_alerts', feature: 'realtime_alerts', label: (_, t) => t('plans.features.realtimeAlerts'), included: (p) => p.limits.realtime_alerts },
    { key: 'flow_triggers', feature: 'flow_triggers', label: (_, t) => t('plans.features.flowTriggers'), included: (p) => p.limits.flow_triggers },
];

/** Flat prices, no GMV share, no contract. Charges go through Shopify. */
export function PlansPage() {
    const { t } = useTranslation();
    const [params, setParams] = useSearchParams();
    const planName = (key: PlanInfo['key']) => t(`plans.names.${key}`);
    const confirmed = params.get('confirmed') === '1';
    const { data, isPending, error, refetch } = useBilling(confirmed);
    const change = useChangePlan();
    const { confirm, modal: confirmModal } = useConfirm();
    const qc = useQueryClient();
    const [interval, setInterval] = useState<Interval>('monthly');

    // Back from Shopify's approval page: show the result, refresh everything plan-dependent.
    useEffect(() => {
        if (confirmed && data) {
            shopify.toast.show(data.plan === 'free' ? t('plans.notApproved') : t('plans.nowOn', { plan: planName(data.plan) }));
            qc.invalidateQueries({ predicate: (q) => q.queryKey[0] !== 'billing' });
            setParams({}, { replace: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [confirmed, data]);

    if (isPending) return <LoadingPage heading={t('nav.plans')} />;
    if (error || !data) {
        return (
            <s-page heading={t('nav.plans')}>
                <ErrorBanner error={error} onRetry={() => refetch()} />
            </s-page>
        );
    }

    const choose = async (plan: PlanInfo) => {
        // Moving to a smaller plan: say exactly what stops working for this shop first.
        const impact = await billingApi.impact(plan.key).catch(() => null);
        if (impact?.downgrade) {
            const ok = await confirm({
                heading: t('downgrade.heading', { plan: t(`plans.names.${plan.key}`) }),
                body: (
                    <s-stack gap="base">
                        {impact.lost.length > 0 ? (
                            <>
                                <s-paragraph>{t('downgrade.intro')}</s-paragraph>
                                <s-unordered-list>
                                    {impact.lost.map((line) => (
                                        <s-list-item key={line.code}>{translateCode('downgrade.lost', line)}</s-list-item>
                                    ))}
                                </s-unordered-list>
                            </>
                        ) : (
                            <s-paragraph>{t('downgrade.nothingLost')}</s-paragraph>
                        )}
                        <s-text color="subdued">{t('downgrade.kept')}</s-text>
                    </s-stack>
                ),
                confirmLabel: plan.key === 'free' ? t('plans.switchToFree') : t('plans.choose', { plan: t(`plans.names.${plan.key}`) }),
                destructive: true,
            });
            if (!ok) return;
        }
        change.mutate({ plan: plan.key, interval: plan.key === 'free' ? undefined : interval });
    };

    return (
        <s-page heading={t('nav.plans')}>
            {change.error && <ErrorBanner error={change.error} />}
            <s-section>
                <s-stack gap="base">
                    <s-paragraph>
                        {t('plans.intro')} {t('plans.tracking', { count: data.usage.tracked_skus, total: formatNumber(data.usage.tracked_skus) })}
                    </s-paragraph>
                    {/* Price lock: Shopify subscriptions keep their price; we never move a shop to a new price. */}
                    <s-stack direction="inline" gap="small-200" alignItems="center">
                        <s-badge tone="success">{t('plans.priceLockBadge')}</s-badge>
                        <s-text>{t('plans.priceLock')}</s-text>
                    </s-stack>
                </s-stack>
            </s-section>

            <IntervalTabs value={interval} onChange={setInterval} />

            <div role="tabpanel" id={`plans-panel-${interval}`} aria-labelledby={`plans-tab-${interval}`}>
                {/* @container columns only work under a query container. */}
                <s-query-container>
                    <s-grid gridTemplateColumns="@container (inline-size > 760px) 1fr 1fr 1fr, 1fr" gap="base">
                        {data.plans.filter((plan) => plan.offered || plan.key === data.plan).map((plan, index, shown) => {
                            // Each plan lists what it adds to the one before it, not the whole list again.
                            const previous = index > 0 ? shown[index - 1] : null;
                            const features = FEATURES.filter((f) => !f.feature || data.entitlements.features[f.feature]).filter(
                                (f) => f.included(plan) && (!previous || !f.included(previous) || f.label(plan, t) !== f.label(previous, t)),
                            );
                            const current = plan.key === data.plan && (plan.key === 'free' || data.interval === interval);
                            const price = plan.prices?.[interval];

                            return (
                                <s-box key={plan.key} border="base" borderRadius="base" padding="base" background="base">
                                    <s-stack gap="base">
                                        <s-stack direction="inline" gap="small-200" alignItems="center">
                                            <s-heading>{planName(plan.key)}</s-heading>
                                            {plan.key === data.plan && <s-badge tone="success">{t('plans.current')}</s-badge>}
                                        </s-stack>
                                        <s-text type="strong">
                                            {price ? t(interval === 'monthly' ? 'plans.perMonth' : 'plans.perYear', { price: formatMoney(price, plan.currency) }) : t('plans.freeForever')}
                                        </s-text>
                                        {price && interval === 'annual' && (
                                            <s-text color="subdued">{t('plans.billedYearly', { price: formatMoney(Math.round((price / 12) * 100) / 100, plan.currency) })}</s-text>
                                        )}
                                        {price && data.trial_days_left > 0 && data.plan === 'free' && <s-text color="subdued">{t('plans.trialNote', { count: data.trial_days_left })}</s-text>}
                                        <s-stack gap="small-200">
                                            {previous && <s-text type="strong">{t('plans.everythingIn', { plan: planName(previous.key) })}</s-text>}
                                            <s-unordered-list>
                                                {features.map((f) => (
                                                    <s-list-item key={f.key}>{f.label(plan, t)}</s-list-item>
                                                ))}
                                            </s-unordered-list>
                                        </s-stack>
                                        <s-button
                                            variant={plan.key === 'free' ? 'secondary' : 'primary'}
                                            disabled={current || undefined}
                                            loading={(change.isPending && change.variables?.plan === plan.key) || undefined}
                                            onClick={() => choose(plan)}
                                        >
                                            {current
                                                ? t('plans.current')
                                                : plan.key === 'free'
                                                  ? t('plans.switchToFree')
                                                  : data.trial_days_left > 0 && data.plan === 'free'
                                                    ? t('plans.startTrial', { count: data.trial_days_left })
                                                    : t('plans.choose', { plan: planName(plan.key) })}
                                        </s-button>
                                    </s-stack>
                                </s-box>
                            );
                        })}
                    </s-grid>
                </s-query-container>
            </div>
            {confirmModal}
        </s-page>
    );
}

const TABS: Interval[] = ['monthly', 'annual'];

/** Polaris web components have no tabs element, so this is a small ARIA tablist styled like admin tabs. */
function IntervalTabs({ value, onChange }: { value: Interval; onChange: (v: Interval) => void }) {
    const { t } = useTranslation();

    const onKeyDown = (e: React.KeyboardEvent) => {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        const next = TABS[(TABS.indexOf(value) + (e.key === 'ArrowRight' ? 1 : TABS.length - 1)) % TABS.length];
        onChange(next);
        document.getElementById(`plans-tab-${next}`)?.focus();
    };

    return (
        <div
            role="tablist"
            aria-label={t('plans.billingPeriod')}
            onKeyDown={onKeyDown}
            style={{
                display: 'flex',
                gap: 4,
                marginBottom: 16,
                flexWrap: 'wrap',
            }}
        >
            {TABS.map((tab) => {
                const selected = tab === value;
                return (
                    <button
                        key={tab}
                        type="button"
                        role="tab"
                        id={`plans-tab-${tab}`}
                        aria-selected={selected}
                        aria-controls={`plans-panel-${tab}`}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => onChange(tab)}
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 6,
                            padding: '6px 12px',
                            border: 0,
                            borderRadius: 8,
                            cursor: 'pointer',
                            font: 'inherit',
                            fontSize: 13,
                            fontWeight: 600,
                            color: selected ? '#303030' : '#616161',
                            background: selected ? 'rgba(0, 0, 0, 0.08)' : 'transparent',
                        }}
                    >
                        {tab === 'monthly' ? t('plans.monthly') : t('plans.yearly')}
                        {tab === 'annual' && <s-badge tone="success">{t('plans.save')}</s-badge>}
                    </button>
                );
            })}
        </div>
    );
}
