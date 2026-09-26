import type { ElementType } from 'react';
import { useTranslation } from 'react-i18next';
import { useFeature } from '@/hooks/useEntitlements';

// App Bridge elements not (yet) in the React JSX typings we use.
const AppNavEl = 's-app-nav' as unknown as ElementType;
const NavLink = 's-link' as unknown as ElementType;

/** App navigation in the Shopify admin sidebar (App Bridge s-app-nav, rendered outside the iframe). */
export function AppNav() {
    const { t } = useTranslation();
    const transfers = useFeature('transfers');
    const whatIf = useFeature('what_if');

    return (
        <AppNavEl>
            <NavLink href="/" rel="home">
                {t('nav.home')}
            </NavLink>
            <NavLink href="/reorder">{t('nav.reorder')}</NavLink>
            {transfers && <NavLink href="/transfers">{t('nav.transfers')}</NavLink>}
            <NavLink href="/products">{t('nav.products')}</NavLink>
            <NavLink href="/insights">{t('nav.insights')}</NavLink>
            {whatIf && <NavLink href="/what-if">{t('nav.whatIf')}</NavLink>}
            <NavLink href="/suppliers">{t('nav.suppliers')}</NavLink>
            <NavLink href="/bundles">{t('nav.bundles')}</NavLink>
            <NavLink href="/settings">{t('nav.settings')}</NavLink>
            <NavLink href="/plans">{t('nav.plans')}</NavLink>
        </AppNavEl>
    );
}
