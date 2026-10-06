import { useTranslation } from 'react-i18next';
import { errorMessage } from '@/lib/http';

type Props = { error: unknown; onRetry?: () => void };

/** Clear, merchant-friendly error with an optional retry action. */
export function ErrorBanner({ error, onRetry }: Props) {
    const { t } = useTranslation();

    return (
        <s-banner tone="critical" heading={t('errors.loadFailed')}>
            <s-paragraph>{errorMessage(error)}</s-paragraph>
            {onRetry && (
                <s-button slot="secondary-actions" onClick={onRetry}>
                    {t('common.tryAgain')}
                </s-button>
            )}
        </s-banner>
    );
}
