import { useTranslation } from 'react-i18next';

export function NotFoundPage() {
    const { t } = useTranslation();

    return (
        <s-page heading={t('notFound.heading')}>
            <s-section>
                <s-paragraph>{t('notFound.body')}</s-paragraph>
                <s-link href="/">{t('notFound.back')}</s-link>
            </s-section>
        </s-page>
    );
}
