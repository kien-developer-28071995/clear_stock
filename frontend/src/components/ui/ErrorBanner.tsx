import { useTranslation } from 'react-i18next';
import { ApiError } from '@/lib/http';

type Props = { error: unknown; onRetry?: () => void };

/** Clear, merchant-friendly error with an optional retry action. */
export function ErrorBanner({ error, onRetry }: Props) {
    const { t } = useTranslation();
    const message = error instanceof ApiError ? error.message : t('errors.generic');

    return (
        <s-banner tone="critical" heading={t('errors.loadFailed')}>
            <s-paragraph>{message}</s-paragraph>
            {onRetry && (
                <s-button slot="secondary-actions" onClick={onRetry}>
                    {t('common.tryAgain')}
                </s-button>
            )}
        </s-banner>
    );
}
