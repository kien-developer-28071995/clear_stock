import { ApiError } from '@/lib/http';

type Props = { error: unknown; onRetry?: () => void };

/** Clear, merchant-friendly error with an optional retry action. */
export function ErrorBanner({ error, onRetry }: Props) {
    const message =
        error instanceof ApiError ? error.message : 'Something went wrong. Please try again.';

    return (
        <s-banner tone="critical" heading="We couldn't load this page">
            <s-paragraph>{message}</s-paragraph>
            {onRetry && (
                <s-button slot="secondary-actions" onClick={onRetry}>
                    Try again
                </s-button>
            )}
        </s-banner>
    );
}
