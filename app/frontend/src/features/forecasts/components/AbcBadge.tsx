import { useTranslation } from 'react-i18next';
import type { AbcClass } from '@/features/forecasts/types';

const TONE: Record<AbcClass, 'info' | 'neutral'> = { A: 'info', B: 'neutral', C: 'neutral' };

/** ABC class badge; nothing for unclassified products (no price). */
export function AbcBadge({ abc }: { abc: AbcClass | null }) {
    const { t } = useTranslation();
    if (!abc) return null;
    return <s-badge tone={TONE[abc]}>{t('abc.class', { abc })}</s-badge>;
}
