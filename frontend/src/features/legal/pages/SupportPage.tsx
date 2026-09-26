import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { PublicLayout, SupportEmail } from '@/features/legal/components/PublicLayout';
import { publicConfig } from '@/features/legal/publicConfig';

const QUESTIONS = ['forecast', 'noForecast', 'stocky', 'billing', 'uninstall'] as const;

/** Support page (public URL for the App Store listing). */
export function SupportPage() {
    const { t } = useTranslation();

    return (
        <PublicLayout title={t('legal.support.title')}>
            <h1>{t('legal.support.title')}</h1>
            <p>
                {t('legal.support.contact', { app: publicConfig.appName })} <SupportEmail />. {t('legal.support.reply')}
            </p>

            <h2>{t('legal.support.faq')}</h2>
            {QUESTIONS.map((k) => (
                <section key={k}>
                    <h3>{t(`legal.support.q.${k}.q`)}</h3>
                    <p>
                        {t(`legal.support.q.${k}.a`)}
                        {k === 'uninstall' && <> <Link to="/privacy">{t('legal.privacy.title')}</Link>.</>}
                    </p>
                </section>
            ))}
        </PublicLayout>
    );
}
