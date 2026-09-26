import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { fieldError } from '@/lib/http';
import { adminLanguage, applyLanguagePreference, currentLocale, languageName, SUPPORTED_LOCALES } from '@/i18n';
import { useQueryClient } from '@tanstack/react-query';
import { shopKeys } from '@/features/shop/hooks/useShop';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { UpgradePrompt } from '@/components/ui/UpgradePrompt';
import { useFeature } from '@/hooks/useEntitlements';
import { useDismissSetupGuide, useSetupGuide } from '@/features/setup/hooks/useSetupGuide';
import { SaveBar } from '@/components/ui/SaveBar';
import { SyncStatusCard } from '@/features/sync/components/SyncStatusCard';
import { FlowSection } from '@/features/settings/components/FlowSection';
import { useSettings, useUpdateSettings } from '@/features/settings/hooks/useSettings';
import type { RealtimeAlertMode, Settings } from '@/features/settings/types';
import { NO_VALUE, fromOption, optionValue } from '@/utils/select';

/** ISO weekday (1 = Monday) → name in the current language. 2024-01-01 was a Monday. */
const weekdayName = (iso: number) =>
    new Intl.DateTimeFormat(currentLocale(), { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, iso)));

/** Store-wide defaults and alert preferences. */
export function SettingsPage() {
    const { t } = useTranslation();
    const { data, isPending, error, refetch } = useSettings();
    const update = useUpdateSettings();
    const qc = useQueryClient();
    const guide = useSetupGuide();
    const reopenGuide = useDismissSetupGuide();
    const [form, setForm] = useState<Settings | null>(null);
    const realtimeExists = useFeature('realtime_alerts');
    const flowExists = useFeature('flow_triggers');

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

    const dirty = JSON.stringify(form) !== JSON.stringify(data);
    const setAlerts = (patch: Partial<Settings['alerts']>) => setForm({ ...form, alerts: { ...form.alerts, ...patch } });
    const { available: _available, realtime_available: _realtimeAvailable, ...alerts } = form.alerts;
    const save = () =>
        update.mutate(
            { ...form, alerts: { ...alerts, email: alerts.email?.trim() || null } as Settings['alerts'] },
            {
                onSuccess: async (saved) => {
                    await applyLanguagePreference(saved.locale);
                    qc.invalidateQueries({ queryKey: shopKeys.all });
                    shopify.toast.show(t('common.saved'));
                },
            },
        );

    return (
        <s-page heading={t('nav.settings')} inlineSize="small">
            <SaveBar id="settings-save-bar" dirty={dirty} saving={update.isPending} onSave={save} onDiscard={() => setForm(data ?? null)} />
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
                    {form.filter_sales_spikes !== null && (
                        <s-switch
                            label={t('settings.filterSpikes')}
                            details={t('settings.filterSpikesHelp')}
                            checked={form.filter_sales_spikes || undefined}
                            onChange={(e) => setForm({ ...form, filter_sales_spikes: e.currentTarget.checked })}
                        />
                    )}
                </s-stack>
            </s-section>

            <s-section heading={t('settings.languageHeading')}>
                <s-select
                    label={t('settings.language')}
                    details={t('settings.languageHelp')}
                    value={optionValue(form.locale)}
                    error={fieldError(update.error, 'locale')}
                    onChange={(e) => setForm({ ...form, locale: fromOption(e.currentTarget.value) || null })}
                >
                    <s-option value={NO_VALUE}>{t('settings.languageAuto', { language: languageName(adminLanguage()) })}</s-option>
                    {SUPPORTED_LOCALES.map((locale) => (
                        <s-option key={locale} value={locale}>{languageName(locale)}</s-option>
                    ))}
                </s-select>
            </s-section>

            <s-section heading={t('settings.alertsHeading')}>
                <s-stack gap="base">
                    {!form.alerts.available && (
                        <UpgradePrompt id="alerts" plan="starter">{t('settings.alertsLocked')}</UpgradePrompt>
                    )}
                    <s-switch
                        label={t('settings.alertsEnabled')}
                        disabled={!form.alerts.available || undefined}
                        checked={form.alerts.enabled || undefined}
                        onChange={(e) => setAlerts({ enabled: e.currentTarget.checked })}
                    />
                    <s-email-field
                        label={t('settings.alertsEmail')}
                        disabled={!form.alerts.available || undefined}
                        value={form.alerts.email ?? ''}
                        error={fieldError(update.error, 'alerts.email')}
                        onInput={(e) => setAlerts({ email: e.currentTarget.value })}
                    />
                    <s-select
                        label={t('settings.frequency')}
                        disabled={!form.alerts.available || undefined}
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
                        disabled={!form.alerts.available || undefined}
                            value={String(form.alerts.weekly_day)}
                            onChange={(e) => setAlerts({ weekly_day: Number(e.currentTarget.value) })}
                        >
                            {[1, 2, 3, 4, 5, 6, 7].map((day) => (
                                <s-option key={day} value={String(day)}>{weekdayName(day)}</s-option>
                            ))}
                        </s-select>
                    )}
                    {realtimeExists && form.alerts.available && !form.alerts.realtime_available && (
                        <UpgradePrompt id="realtime-alerts" plan="growth">{t('settings.realtimeLocked')}</UpgradePrompt>
                    )}
                    {realtimeExists && (<s-select
                        label={t('settings.realtime')}
                        disabled={!form.alerts.realtime_available || undefined}
                        details={t('settings.realtimeHelp')}
                        value={form.alerts.realtime}
                        onChange={(e) => setAlerts({ realtime: e.currentTarget.value as RealtimeAlertMode })}
                    >
                        <s-option value="off">{t('settings.realtimeOff')}</s-option>
                        <s-option value="out_of_stock">{t('settings.realtimeOutOfStock')}</s-option>
                        <s-option value="all">{t('settings.realtimeAll')}</s-option>
                    </s-select>)}
                </s-stack>
            </s-section>

            {flowExists && <FlowSection flow={form.flow} />}

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
            <SyncStatusCard />
        </s-page>
    );
}
