import { useTranslation } from 'react-i18next';
import { useDismissTip, useSetupGuide } from '@/features/setup/hooks/useSetupGuide';
import type { TipKey } from '@/features/setup/types';

/** A one-time contextual hint. Shown until dismissed (remembered per shop). */
export function Tip({ id, children }: { id: TipKey; children: string }) {
    const { t } = useTranslation();
    const { data } = useSetupGuide();
    const dismiss = useDismissTip();

    if (!data || data.tips_dismissed.includes(id)) return null;

    return (
        <s-banner tone="info" dismissible onDismiss={() => dismiss.mutate(id)}>
            <s-paragraph>
                <s-text type="strong">{t('tips.label')} </s-text>
                {children}
            </s-paragraph>
        </s-banner>
    );
}
