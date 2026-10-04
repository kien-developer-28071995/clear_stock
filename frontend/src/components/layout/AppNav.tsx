import type { ElementType } from 'react';
import { useTranslation } from 'react-i18next';
import { useSectionTabs } from '@/components/layout/SectionTabs';

// App Bridge elements not (yet) in the React JSX typings we use.
const AppNavEl = 's-app-nav' as unknown as ElementType;
const NavLink = 's-link' as unknown as ElementType;

/** App navigation in the Shopify admin sidebar (App Bridge s-app-nav, rendered outside the iframe). */
export function AppNav() {
    const { t } = useTranslation();
    const planning = useSectionTabs('planning').length > 0;

    return (
        <AppNavEl>
            <NavLink href="/" rel="home">
                {t('nav.home')}
            </NavLink>
            <NavLink href="/reorder">{t('nav.reorder')}</NavLink>
            <NavLink href="/products">{t('nav.products')}</NavLink>
            <NavLink href="/insights">{t('nav.insights')}</NavLink>
            {planning && <NavLink href="/planning">{t('nav.planning')}</NavLink>}
            <NavLink href="/suppliers">{t('nav.suppliers')}</NavLink>
            <NavLink href="/settings">{t('nav.settings')}</NavLink>
            <NavLink href="/plans">{t('nav.plans')}</NavLink>
        </AppNavEl>
    );
}
