import { useNavigate } from 'react-router';
import { useTranslation } from 'react-i18next';
import type { RunwayItem } from '@/features/dashboard/types';
import { formatNumber } from '@/utils/format';

// Admin surfaces are light; these match Polaris critical / success fills.
const BELOW = '#e51c00';
const COVERED = '#29845a';
const TRACK = '#f1f1f1';

/**
 * Days of stock left per product, with each product's own "reorder in time" line
 * (lead time + safety days). Red bars are below their line.
 */
export function RunwayChart({ items }: { items: RunwayItem[] }) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    if (items.length === 0) return null;

    const max = Math.max(30, ...items.map((i) => Math.max(i.days_of_cover, i.reorder_days))) * 1.1;
    const pct = (d: number) => `${Math.min(100, (d / max) * 100)}%`;

    return (
        <s-section heading={t('runway.heading')}>
            <s-stack gap="small-300">
                <s-text color="subdued">{t('runway.help')}</s-text>
                <div role="list" aria-label={t('runway.listLabel')}>
                    {items.map((item) => {
                        const below = item.days_of_cover < item.reorder_days;
                        return (
                            <div
                                key={item.variant_id}
                                role="listitem"
                                onClick={() => navigate(`/products/${item.variant_id}`)}
                                title={t('runway.barTitle', { name: item.name, count: Math.round(item.days_of_cover), reorder: item.reorder_days })}
                                style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 200px) minmax(0, 1fr) 48px', gap: 12, alignItems: 'center', height: 30, cursor: 'pointer' }}
                            >
                                <s-text>
                                    <span style={{ display: 'block', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{item.name}</span>
                                </s-text>
                                <div style={{ position: 'relative', height: 12, background: TRACK, borderRadius: 3 }}>
                                    <div style={{ width: pct(item.days_of_cover), minWidth: 3, height: 12, background: below ? BELOW : COVERED, borderRadius: 3 }} />
                                    <div style={{ position: 'absolute', left: pct(item.reorder_days), top: -5, bottom: -5, borderLeft: '1.5px dashed #616161' }} />
                                </div>
                                <s-text type="strong">
                                    <span style={{ display: 'block', textAlign: 'right' }}>{t('runway.daysShort', { days: formatNumber(item.days_of_cover, 0) })}</span>
                                </s-text>
                            </div>
                        );
                    })}
                </div>
            </s-stack>
        </s-section>
    );
}
