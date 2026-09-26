import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import { useDismissSetupGuide, useSetupGuide, useSkipSetupStep } from '@/features/setup/hooks/useSetupGuide';
import type { SetupGuideState, SetupStep, SetupStepKey } from '@/features/setup/types';

interface StepCopy {
    title: string;
    body: string;
    action?: { label: string; href: string };
    skipLabel?: string;
}

function copy(key: SetupStepKey, ctx: SetupGuideState['context'], t: TFunction): StepCopy {
    switch (key) {
        case 'import_data':
            return {
                title: t('setup.steps.import_data.title'),
                body: ctx.sync_running ? t('setup.steps.import_data.bodyRunning') : t('setup.steps.import_data.body'),
            };
        case 'lead_time':
            return {
                title: t('setup.steps.lead_time.title'),
                body: t('setup.steps.lead_time.body'),
                action: { label: t('setup.steps.lead_time.action'), href: '/settings' },
            };
        case 'review_forecast':
            return {
                title: t('setup.steps.review_forecast.title'),
                body: t('setup.steps.review_forecast.body'),
                action: ctx.example_variant
                    ? { label: t('setup.steps.review_forecast.actionExample', { name: ctx.example_variant.name }), href: `/products/${ctx.example_variant.id}` }
                    : { label: t('setup.steps.review_forecast.action'), href: '/products' },
            };
        case 'suppliers':
            return {
                title: t('setup.steps.suppliers.title'),
                body: t('setup.steps.suppliers.body'),
                // Shopify vendors can become suppliers in one step.
                action: ctx.vendor_count > 0
                    ? { label: t('setup.steps.suppliers.fromVendors', { count: ctx.vendor_count }), href: '/suppliers/from-vendors' }
                    : { label: t('setup.steps.suppliers.action'), href: '/suppliers' },
                skipLabel: t('setup.steps.suppliers.skip'),
            };
        case 'alerts':
            return ctx.alerts_available
                ? {
                      title: t('setup.steps.alerts.title'),
                      body: t('setup.steps.alerts.body'),
                      action: { label: t('setup.steps.alerts.action'), href: '/settings' },
                      skipLabel: t('setup.notNow'),
                  }
                : {
                      title: t('setup.steps.alerts.lockedTitle'),
                      body: t('setup.steps.alerts.lockedBody'),
                      action: { label: t('upgrade.seePlans'), href: '/plans' },
                      skipLabel: t('setup.notNow'),
                  };
    }
}

function StepRow({ step, state, open, onOpen }: { step: SetupStep; state: SetupGuideState; open: boolean; onOpen: () => void }) {
    const { t } = useTranslation();
    const skip = useSkipSetupStep();
    const c = copy(step.key, state.context, t);
    const finished = step.done || step.skipped;

    return (
        <s-box padding="small-200 base" borderWidth="small none none none" borderColor="base" background={open ? 'subdued' : undefined}>
            <s-stack gap="small-200">
                <s-stack direction="inline" gap="small-200" alignItems="center">
                    <s-icon type={finished ? 'check-circle' : 'circle-dashed'} tone={step.done ? 'success' : undefined} />
                    {finished || open ? (
                        <s-text type={open ? 'strong' : undefined} color={finished ? 'subdued' : undefined}>
                            {step.skipped ? t('setup.skipped', { title: c.title }) : c.title}
                        </s-text>
                    ) : (
                        <s-link onClick={onOpen}>{c.title}</s-link>
                    )}
                </s-stack>
                {open && !finished && (
                    <s-stack gap="small-200">
                        <s-text color="subdued">{c.body}</s-text>
                        <s-stack direction="inline" gap="small-200">
                            {c.action && (
                                <s-button variant="primary" href={c.action.href}>
                                    {c.action.label}
                                </s-button>
                            )}
                            {step.skippable && c.skipLabel && (
                                <s-button variant="tertiary" onClick={() => skip.mutate(step.key)} loading={skip.isPending || undefined}>
                                    {c.skipLabel}
                                </s-button>
                            )}
                        </s-stack>
                    </s-stack>
                )}
            </s-stack>
        </s-box>
    );
}

/**
 * Home setup guide (Shopify onboarding guidance: at most five steps, completed
 * automatically, progress shown, dismissible). Hidden once everything is done.
 */
export function SetupGuide() {
    const { t } = useTranslation();
    const { data } = useSetupGuide();
    const dismiss = useDismissSetupGuide();
    const [collapsed, setCollapsed] = useState(false);
    const [openKey, setOpenKey] = useState<SetupStepKey | null>(null);

    if (!data || data.dismissed || data.completed === data.total) return null;

    const firstTodo = data.steps.find((s) => !s.done && !s.skipped)?.key ?? null;
    const open = openKey && data.steps.some((s) => s.key === openKey && !s.done && !s.skipped) ? openKey : firstTodo;

    return (
        <s-section padding="none">
            <s-box padding="base">
                <s-stack gap="small-200">
                    <s-grid gridTemplateColumns="1fr auto" gap="base" alignItems="start">
                        <s-stack gap="small-100">
                            <s-heading>{t('setup.heading')}</s-heading>
                            <s-text color="subdued">{t('setup.subheading')}</s-text>
                        </s-stack>
                        <s-stack direction="inline" gap="small-100">
                            <s-button
                                variant="tertiary"
                                icon={collapsed ? 'chevron-down' : 'chevron-up'}
                                accessibilityLabel={collapsed ? t('setup.expand') : t('setup.collapse')}
                                onClick={() => setCollapsed(!collapsed)}
                            />
                            <s-button
                                variant="tertiary"
                                icon="x"
                                accessibilityLabel={t('setup.dismiss')}
                                onClick={() => dismiss.mutate(true)}
                            />
                        </s-stack>
                    </s-grid>
                    <s-stack direction="inline" gap="small-200" alignItems="center">
                        <s-text color="subdued">{t('setup.progress', { done: data.completed, total: data.total })}</s-text>
                        <s-box inlineSize="200px">
                            <s-progress value={data.completed} max={data.total} accessibilityLabel={t('setup.progressLabel')} />
                        </s-box>
                    </s-stack>
                </s-stack>
            </s-box>
            {!collapsed &&
                data.steps.map((step) => (
                    <StepRow key={step.key} step={step} state={data} open={step.key === open} onOpen={() => setOpenKey(step.key)} />
                ))}
        </s-section>
    );
}
