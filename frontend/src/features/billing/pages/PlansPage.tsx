import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import type { TFunction } from 'i18next';
import { useQueryClient } from '@tanstack/react-query';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { useConfirm } from '@/components/ui/ConfirmModal';
import { useBilling, useChangePlan } from '@/features/billing/hooks/useBilling';
import type { Interval, PlanInfo } from '@/features/billing/types';
import { formatMoney, formatNumber } from '@/utils/format';

const FEATURES: { key: string; label: (p: PlanInfo, t: TFunction) => string; included: (p: PlanInfo) => boolean }[] = [
    {
        key: 'skus',
        label: (p, t) => (p.limits.max_skus ? t('plans.features.bestSellers', { count: p.limits.max_skus }) : t('plans.features.unlimited')),
        included: () => true,
    },
    { key: 'forecasts', label: (_, t) => t('plans.features.forecasts'), included: () => true },
    { key: 'explanations', label: (_, t) => t('plans.features.explanations'), included: (p) => p.limits.explanations },
    { key: 'bundles', label: (_, t) => t('plans.features.bundles'), included: (p) => p.limits.bundles },
    { key: 'alerts', label: (_, t) => t('plans.features.alerts'), included: (p) => p.limits.alerts },
    { key: 'locations', label: (_, t) => t('plans.features.locations'), included: (p) => p.limits.locations },
    { key: 'purchase_orders', label: (_, t) => t('plans.features.purchaseOrders'), included: (p) => p.limits.purchase_orders },
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

    useEffect(() => {
        if (data?.interval) setInterval(data.interval);
    }, [data?.interval]);

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
        if (
            plan.key === 'free' &&
            !(await confirm({ heading: t('confirm.switchToFree'), body: t('plans.confirmFree', { count: plan.limits.max_skus ?? 0 }), confirmLabel: t('plans.switchToFree'), destructive: true }))
        ) {
            return;
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
                    <s-button-group>
                        <s-button slot="secondary-actions" variant={interval === 'monthly' ? 'primary' : 'secondary'} onClick={() => setInterval('monthly')}>
                            {t('plans.monthly')}
                        </s-button>
                        <s-button slot="secondary-actions" variant={interval === 'annual' ? 'primary' : 'secondary'} onClick={() => setInterval('annual')}>
                            {t('plans.yearly')}
                        </s-button>
                    </s-button-group>
                </s-stack>
            </s-section>

            <s-grid gridTemplateColumns="@container (inline-size > 700px) 1fr 1fr 1fr, 1fr" gap="base">
                {data.plans.map((plan) => {
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
                                    {price
                                        ? t(interval === 'monthly' ? 'plans.perMonth' : 'plans.perYear', { price: formatMoney(price, plan.currency) })
                                        : t('plans.freeForever')}
                                </s-text>
                                {price && data.trial_days_left > 0 && data.plan === 'free' && (
                                    <s-text color="subdued">{t('plans.trialNote', { count: data.trial_days_left })}</s-text>
                                )}
                                <s-unordered-list>
                                    {FEATURES.map((f) => (
                                        <s-list-item key={f.key}>
                                            {f.included(plan) ? '✓ ' : '— '}
                                            {f.label(plan, t)}
                                        </s-list-item>
                                    ))}
                                </s-unordered-list>
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
            {confirmModal}
        </s-page>
    );
}
