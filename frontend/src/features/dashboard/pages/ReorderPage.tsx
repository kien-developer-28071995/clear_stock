import { useTranslation } from 'react-i18next';
import { DashboardGate } from '@/features/dashboard/components/DashboardGate';
import { ActionList } from '@/features/dashboard/components/ActionList';
import { Tip } from '@/features/setup/components/Tip';

/** Everything to reorder in the next 7 days, grouped by urgency, ready to export as a purchase order. */
export function ReorderPage() {
    const { t } = useTranslation();

    return (
        <DashboardGate heading={t('nav.reorder')}>
            {(data) => (
                <s-page heading={t('nav.reorder')}>
                    <s-link slot="breadcrumb-actions" href="/">{t('nav.home')}</s-link>
                    <Tip id="home_actions">{t('tips.home_actions')}</Tip>
                    <ActionList dashboard={data} />
                </s-page>
            )}
        </DashboardGate>
    );
}
