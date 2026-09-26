import { useTranslation } from 'react-i18next';
import type { Confidence, ForecastStatus } from '@/types/forecast';

const STATUS_TONE: Record<ForecastStatus, 'critical' | 'warning' | 'neutral' | 'success' | 'caution'> = {
    out_of_stock: 'critical',
    reorder_now: 'warning',
    slow: 'neutral',
    overstock: 'caution',
    healthy: 'success',
};

export function StatusBadge({ status }: { status: ForecastStatus }) {
    const { t } = useTranslation();
    return <s-badge tone={STATUS_TONE[status]}>{t(`status.${status}`)}</s-badge>;
}

const CONFIDENCE_TONE: Record<Confidence, 'caution' | 'neutral' | 'success'> = {
    low: 'caution',
    medium: 'neutral',
    high: 'success',
};

export function ConfidenceBadge({ confidence }: { confidence: Confidence }) {
    const { t } = useTranslation();
    return <s-badge tone={CONFIDENCE_TONE[confidence]}>{t(`confidence.${confidence}`)}</s-badge>;
}
