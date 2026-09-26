import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { fieldError } from '@/lib/http';
import { appConfig } from '@/lib/appConfig';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { SyncStatusCard } from '@/features/sync/components/SyncStatusCard';
import { useSyncStatus } from '@/features/sync/hooks/useSync';
import { useCompleteOnboarding, useOnboarding } from '@/features/onboarding/hooks/useOnboarding';

/**
 * First run: the sync starts automatically on install; meanwhile we ask the only
 * two questions we need. Everything else (suppliers, per-SKU lead times, bundles)
 * can be set later in Settings.
 */
export function OnboardingPage() {
    const { t } = useTranslation();
    const { data, isPending } = useOnboarding();
    const complete = useCompleteOnboarding();
    const sync = useSyncStatus();
    const imported = sync.data?.status === 'completed';
    const [leadTime, setLeadTime] = useState('14');
    const [email, setEmail] = useState('');

    useEffect(() => {
        if (data) {
            setLeadTime(String(data.lead_time_days));
            setEmail(data.alert_email ?? '');
        }
    }, [data]);

    const heading = t('onboarding.heading', { app: appConfig.appName });
    if (isPending) return <LoadingPage heading={heading} />;

    const submit = () =>
        complete.mutate({ lead_time_days: Number(leadTime) || 14, alert_email: email.trim() || null });

    return (
        <s-page heading={heading} inlineSize="small">
            <s-section>
                <s-paragraph>{imported ? t('onboarding.introImported') : t('onboarding.introImporting')}</s-paragraph>
            </s-section>

            <SyncStatusCard />

            <s-section heading={t('onboarding.questionsHeading')}>
                <s-stack gap="base">
                    <s-number-field
                        label={t('onboarding.leadTimeLabel')}
                        details={t('onboarding.leadTimeHelp')}
                        suffix={t('common.days')}
                        min={1}
                        max={365}
                        value={leadTime}
                        error={fieldError(complete.error, 'lead_time_days')}
                        onInput={(e) => setLeadTime(e.currentTarget.value)}
                    />
                    <s-email-field
                        label={t('onboarding.emailLabel')}
                        details={t('onboarding.emailHelp')}
                        value={email}
                        error={fieldError(complete.error, 'alert_email')}
                        onInput={(e) => setEmail(e.currentTarget.value)}
                    />
                    <s-button variant="primary" onClick={submit} loading={complete.isPending || undefined}>
                        {t('onboarding.submit')}
                    </s-button>
                </s-stack>
            </s-section>
        </s-page>
    );
}
