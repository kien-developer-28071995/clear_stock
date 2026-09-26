import { useTranslation } from 'react-i18next';
import type { Dashboard } from '@/features/dashboard/types';
import { timeAgo } from '@/utils/format';

function greetingKey(): 'home.greetingMorning' | 'home.greetingAfternoon' | 'home.greetingEvening' {
    const h = new Date().getHours();
    return h < 12 ? 'home.greetingMorning' : h < 18 ? 'home.greetingAfternoon' : 'home.greetingEvening';
}

/** One sentence that says what today needs, plus quick links into the product list. */
export function HomeHeader({ dashboard }: { dashboard: Dashboard }) {
    const { t } = useTranslation();
    const { counts, actions, slow_movers: slow } = dashboard;
    const now = actions.out_of_stock.length + actions.order_today.length;

    return (
        <s-stack gap="small-300">
            <s-heading>
                {t(greetingKey())} {now === 0 ? t('home.nothingToday') : t('home.toReorderToday', { count: now })}
            </s-heading>
            {dashboard.forecasted_at && <s-text color="subdued">{t('home.forecastUpdated', { when: timeAgo(dashboard.forecasted_at) })}</s-text>}
            <s-stack direction="inline" gap="small-200">
                <s-clickable-chip href="/products?status=out_of_stock">{t('home.chip', { label: t('status.out_of_stock'), n: counts.out_of_stock })}</s-clickable-chip>
                <s-clickable-chip href="/products?status=reorder_now">{t('home.chip', { label: t('status.reorder_now'), n: counts.reorder_now })}</s-clickable-chip>
                <s-clickable-chip href="/products?status=slow">{t('home.chip', { label: t('status.slow'), n: slow.count })}</s-clickable-chip>
                <s-clickable-chip href="/products?status=healthy">{t('home.chip', { label: t('status.healthy'), n: counts.healthy })}</s-clickable-chip>
            </s-stack>
        </s-stack>
    );
}
