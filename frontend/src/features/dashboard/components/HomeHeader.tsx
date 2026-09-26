import { useTranslation } from 'react-i18next';
import type { Dashboard } from '@/features/dashboard/types';
import { timeAgo } from '@/utils/format';

function greetingKey(): 'home.greetingMorning' | 'home.greetingAfternoon' | 'home.greetingEvening' {
    const h = new Date().getHours();
    return h < 12 ? 'home.greetingMorning' : h < 18 ? 'home.greetingAfternoon' : 'home.greetingEvening';
}

/** One sentence that says what today needs. */
export function HomeHeader({ dashboard }: { dashboard: Dashboard }) {
    const { t } = useTranslation();
    const { actions } = dashboard;
    const now = actions.out_of_stock.length + actions.order_today.length;

    return (
        <s-stack gap="small-100">
            <s-heading>
                {t(greetingKey())} {now === 0 ? t('home.nothingToday') : t('home.toReorderToday', { count: now })}
            </s-heading>
            {dashboard.forecasted_at && <s-text color="subdued">{t('home.forecastUpdated', { when: timeAgo(dashboard.forecasted_at) })}</s-text>}
        </s-stack>
    );
}
