import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { feedbackApi } from '@/features/feedback/api/feedbackApi';
import { useModal } from '@/hooks/useModal';
import { useSubmitOnce } from '@/hooks/useSubmitOnce';
import { fieldError } from '@/lib/http';

const MAX = 2000;
const MIN = 5;

/** Settings: a box to tell us what is missing or wrong. Goes to the support address by email; nothing is stored. */
export function FeedbackSection({ defaultEmail }: { defaultEmail: string | null }) {
    const { t } = useTranslation();
    const modal = useModal();
    const once = useSubmitOnce();
    const send = useMutation({ mutationFn: feedbackApi.send });
    const [message, setMessage] = useState('');
    const [email, setEmail] = useState('');

    const reset = () => {
        setMessage('');
        setEmail(defaultEmail ?? '');
        send.reset();
    };

    const submit = () => once((done) => {
        send.mutate(
            { message: message.trim(), email: email.trim() || null },
            {
                onSuccess: () => {
                    shopify.toast.show(t('feedback.sent'));
                    modal.close();
                },
                onSettled: done,
            },
        );
    });

    return (
        <s-section heading={t('feedback.heading')}>
            <s-stack gap="base">
                <s-paragraph>{t('feedback.body')}</s-paragraph>
                <s-stack direction="inline">
                    <s-button onClick={() => modal.open()}>{t('feedback.open')}</s-button>
                </s-stack>
            </s-stack>
            {/* The form starts clean every time it opens (also after "type, dismiss, open again"). */}
            <s-modal ref={modal.ref} id="feedback-modal" heading={t('feedback.heading')} onShow={reset}>
                <s-stack gap="base">
                    <s-text-area
                        label={t('feedback.message')}
                        rows={6}
                        maxLength={MAX}
                        value={message}
                        error={fieldError(send.error, 'message')}
                        onInput={(e) => setMessage(e.currentTarget.value)}
                    />
                    <s-email-field
                        label={t('feedback.email')}
                        details={t('feedback.emailHelp')}
                        value={email}
                        error={fieldError(send.error, 'email')}
                        onInput={(e) => setEmail(e.currentTarget.value)}
                    />
                </s-stack>
                <s-button slot="primary-action" variant="primary" onClick={submit} loading={send.isPending || undefined} disabled={message.trim().length < MIN || undefined}>
                    {t('feedback.send')}
                </s-button>
                <s-button slot="secondary-actions" commandFor="feedback-modal" command="--hide">
                    {t('common.cancel')}
                </s-button>
            </s-modal>
        </s-section>
    );
}
