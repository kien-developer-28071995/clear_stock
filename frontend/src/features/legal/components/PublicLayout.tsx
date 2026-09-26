import type { ReactNode } from 'react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { publicConfig } from '@/features/legal/publicConfig';

/** Standalone page (outside the Shopify admin): no App Bridge, no Polaris CDN. */
export function PublicLayout({ title, children }: { title: string; children: ReactNode }) {
    const { t } = useTranslation();

    useEffect(() => {
        document.title = `${title} · ${publicConfig.appName}`;
    }, [title]);

    return (
        <>
            <main className="legal">{children}</main>
            <footer className="legal-footer">
                <Link to="/privacy">{t('legal.privacy.title')}</Link> · <Link to="/support">{t('legal.support.title')}</Link>
            </footer>
        </>
    );
}

/** The support address as a mailto link (empty when not configured). */
export function SupportEmail() {
    const email = publicConfig.supportEmail;
    return email ? <a href={`mailto:${email}`}>{email}</a> : null;
}
