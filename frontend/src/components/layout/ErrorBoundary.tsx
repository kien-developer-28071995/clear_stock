import { Component, type ErrorInfo, type ReactNode } from 'react';
import i18n from 'i18next';
import { reportClientError } from '@/lib/errorReporting';

/** A crash in one screen shows a friendly message instead of a blank page, and is reported. */
export class ErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        reportClientError(error, info.componentStack ?? undefined);
    }

    render() {
        if (!this.state.failed) return this.props.children;

        return (
            <s-page>
                <s-banner tone="critical" heading={i18n.t('errors.crashHeading')}>
                    <s-paragraph>{i18n.t('errors.crashBody')}</s-paragraph>
                    <s-button slot="secondary-actions" onClick={() => location.reload()}>
                        {i18n.t('errors.reload')}
                    </s-button>
                </s-banner>
            </s-page>
        );
    }
}
