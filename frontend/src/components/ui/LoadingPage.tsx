import { useTranslation } from 'react-i18next';

export function LoadingPage({ heading }: { heading: string }) {
    const { t } = useTranslation();

    return (
        <s-page heading={heading}>
            <s-section>
                <s-stack direction="inline" gap="small-200" alignItems="center">
                    <s-spinner accessibilityLabel={t('common.loading')} size="base" />
                    <s-text color="subdued">{t('common.loadingEllipsis')}</s-text>
                </s-stack>
            </s-section>
        </s-page>
    );
}
