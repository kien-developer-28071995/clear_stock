import { NavMenu } from '@shopify/app-bridge-react';
import { useTranslation } from 'react-i18next';

/** Sidebar navigation in the Shopify admin (rendered outside the iframe by App Bridge). */
export function AppNav() {
    const { t } = useTranslation();

    return (
        <NavMenu>
            <a href="/" rel="home">
                {t('nav.home')}
            </a>
            <a href="/products">{t('nav.products')}</a>
            <a href="/suppliers">{t('nav.suppliers')}</a>
            <a href="/bundles">{t('nav.bundles')}</a>
            <a href="/settings">{t('nav.settings')}</a>
            <a href="/plans">{t('nav.plans')}</a>
        </NavMenu>
    );
}
