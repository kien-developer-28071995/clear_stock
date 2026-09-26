import { useTranslation } from 'react-i18next';
import { PublicLayout, SupportEmail } from '@/features/legal/components/PublicLayout';
import { publicConfig } from '@/features/legal/publicConfig';
import { currentLocale } from '@/i18n';

/** Full date with the year (a policy date must be unambiguous). */
const longDate = (ymd: string) => new Intl.DateTimeFormat(currentLocale(), { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(`${ymd}T00:00:00Z`));

/** Keys under legal.privacy.<section>.<item>: a bold label and its text. */
const READS = ['products', 'inventory', 'orders', 'store'] as const;
const STORES = ['sales', 'catalog', 'entered', 'shop', 'emails'] as const;
const RETENTION = ['uninstall', 'erase', 'sooner'] as const;

function Item({ label, children }: { label?: string; children: React.ReactNode }) {
    return (
        <li>
            {label && <strong>{label}: </strong>}
            {children}
        </li>
    );
}

/** Privacy policy (public URL for the App Store listing). Keep it true to what the backend stores. */
export function PrivacyPage() {
    const { t } = useTranslation();
    const app = publicConfig.appName;

    return (
        <PublicLayout title={t('legal.privacy.title')}>
            <h1>{t('legal.privacy.title')}</h1>
            {publicConfig.privacyUpdated && <p><em>{t('legal.privacy.updated', { date: longDate(publicConfig.privacyUpdated) })}</em></p>}
            <p>{t('legal.privacy.intro', { app })}</p>

            <h2>{t('legal.privacy.reads.heading')}</h2>
            <ul>
                {READS.map((k) => (
                    <Item key={k} label={t(`legal.privacy.reads.${k}Label`)}>{t(`legal.privacy.reads.${k}`)}</Item>
                ))}
            </ul>

            <h2>{t('legal.privacy.stores.heading')}</h2>
            <ul>
                {STORES.map((k) => (
                    <Item key={k}>{t(`legal.privacy.stores.${k}`)}</Item>
                ))}
            </ul>
            <p><strong>{t('legal.privacy.stores.noCustomers')}</strong> {t('legal.privacy.stores.noCustomersBody')}</p>

            <h2>{t('legal.privacy.use.heading')}</h2>
            <p>{t('legal.privacy.use.body')}</p>

            <h2>{t('legal.privacy.providers.heading')}</h2>
            <p>{t('legal.privacy.providers.body')}</p>

            <h2>{t('legal.privacy.retention.heading')}</h2>
            <ul>
                {RETENTION.map((k) => (
                    <Item key={k}>
                        {t(`legal.privacy.retention.${k}`)}
                        {k === 'sooner' && <> <SupportEmail />.</>}
                    </Item>
                ))}
            </ul>

            <h2>{t('legal.privacy.security.heading')}</h2>
            <p>{t('legal.privacy.security.body')}</p>

            <h2>{t('legal.privacy.contact.heading')}</h2>
            <p>
                {t('legal.privacy.contact.body')} <SupportEmail />
            </p>
        </PublicLayout>
    );
}
