import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { fieldError } from '@/lib/http';
import { currentLocale } from '@/i18n';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { UpgradePrompt } from '@/components/ui/UpgradePrompt';
import { useDismissSetupGuide, useSetupGuide } from '@/features/setup/hooks/useSetupGuide';
import { useSettings, useUpdateSettings } from '@/features/settings/hooks/useSettings';
import type { Settings } from '@/features/settings/types';

/** ISO weekday (1 = Monday) → name in the current language. 2024-01-01 was a Monday. */
const weekdayName = (iso: number) =>
    new Intl.DateTimeFormat(currentLocale(), { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, iso)));

/** Store-wide defaults and alert preferences. */
export function SettingsPage() {
    const { t } = useTranslation();
    const { data, isPending, error, refetch } = useSettings();
    const update = useUpdateSettings();
    const guide = useSetupGuide();
    const reopenGuide = useDismissSetupGuide();
    const [form, setForm] = useState<Settings | null>(null);

    useEffect(() => {
        if (data) setForm(data);
    }, [data]);

    if (isPending || !form) {
        return error ? (
            <s-page heading={t('nav.settings')}>
                <ErrorBanner error={error} onRetry={() => refetch()} />
            </s-page>
        ) : (
            <LoadingPage heading={t('nav.settings')} />
        );
    }

    const setAlerts = (patch: Partial<Settings['alerts']>) => setForm({ ...form, alerts: { ...form.alerts, ...patch } });
    const { available: _available, ...alerts } = form.alerts;
    const save = () =>
        update.mutate(
            { ...form, alerts: { ...alerts, email: alerts.email?.trim() || null } as Settings['alerts'] },
            { onSuccess: () => shopify.toast.show(t('common.saved')) },
        );

    return (
        <s-page heading={t('nav.settings')} inlineSize="small">
            <s-section heading={t('settings.defaultsHeading')}>
                <s-stack gap="base">
                    <s-paragraph>{t('settings.defaultsIntro')}</s-paragraph>
                    <s-number-field
                        label={t('settings.leadTime')}
                        suffix={t('common.daysSuffix')}
                        min={1}
                        max={365}
                        details={t('settings.leadTimeHelp')}
                        value={String(form.default_lead_time_days)}
                        error={fieldError(update.error, 'default_lead_time_days')}
                        onInput={(e) => setForm({ ...form, default_lead_time_days: Number(e.currentTarget.value) })}
                    />
                    <s-number-field
                        label={t('settings.safety')}
                        suffix={t('common.daysSuffix')}
                        min={0}
                        max={365}
                        details={t('productSettings.safetyHelp')}
                        value={String(form.default_safety_days)}
                        error={fieldError(update.error, 'default_safety_days')}
                        onInput={(e) => setForm({ ...form, default_safety_days: Number(e.currentTarget.value) })}
                    />
                </s-stack>
            </s-section>

            <s-section heading={t('settings.alertsHeading')}>
                <s-stack gap="base">
                    {!form.alerts.available && (
                        <UpgradePrompt plan="starter">{t('settings.alertsLocked')}</UpgradePrompt>
                    )}
                    <s-switch
                        label={t('settings.alertsEnabled')}
                        checked={form.alerts.enabled || undefined}
                        onChange={(e) => setAlerts({ enabled: e.currentTarget.checked })}
                    />
                    <s-email-field
                        label={t('settings.alertsEmail')}
                        value={form.alerts.email ?? ''}
                        error={fieldError(update.error, 'alerts.email')}
                        onInput={(e) => setAlerts({ email: e.currentTarget.value })}
                    />
                    <s-select
                        label={t('settings.frequency')}
                        details={t('settings.frequencyHelp')}
                        value={form.alerts.frequency}
                        onChange={(e) => setAlerts({ frequency: e.currentTarget.value as Settings['alerts']['frequency'] })}
                    >
                        <s-option value="daily">{t('settings.daily')}</s-option>
                        <s-option value="weekly">{t('settings.weekly')}</s-option>
                    </s-select>
                    {form.alerts.frequency === 'weekly' && (
                        <s-select
                            label={t('settings.sendOn')}
                            value={String(form.alerts.weekly_day)}
                            onChange={(e) => setAlerts({ weekly_day: Number(e.currentTarget.value) })}
                        >
                            {[1, 2, 3, 4, 5, 6, 7].map((day) => (
                                <s-option key={day} value={String(day)}>{weekdayName(day)}</s-option>
                            ))}
                        </s-select>
                    )}
                </s-stack>
            </s-section>

            <s-stack direction="inline">
                <s-button variant="primary" onClick={save} loading={update.isPending || undefined}>
                    {t('common.save')}
                </s-button>
            </s-stack>

            {guide.data?.dismissed && guide.data.completed < guide.data.total && (
                <s-section heading={t('settings.setupGuide')}>
                    <s-stack gap="small-200">
                        <s-text color="subdued">{t('setup.progress', { done: guide.data.completed, total: guide.data.total })}</s-text>
                        <s-stack direction="inline">
                            <s-button
                                onClick={() => reopenGuide.mutate(false, { onSuccess: () => shopify.toast.show(t('settings.setupGuideBack')) })}
                                loading={reopenGuide.isPending || undefined}
                            >
                                {t('settings.showSetupGuide')}
                            </s-button>
                        </s-stack>
                    </s-stack>
                </s-section>
            )}
        </s-page>
    );
}
