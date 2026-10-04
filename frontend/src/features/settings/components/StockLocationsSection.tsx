import { useTranslation } from 'react-i18next';
import type { StockLocation } from '@/features/settings/types';

interface Props {
    locations: StockLocation[] | undefined;
    /** Ids left out of the stock, as edited (saved with the rest of the Settings form). */
    excluded: number[];
    onChange: (excluded: number[]) => void;
    error?: string;
}

/**
 * Which locations' stock the forecasts count. Shown with two or more locations: a returns
 * or damaged-goods location would otherwise make products look better stocked than they are.
 */
export function StockLocationsSection({ locations, excluded, onChange, error }: Props) {
    const { t } = useTranslation();
    if (!locations || locations.length < 2) return null;

    const counted = locations.length - excluded.length;

    return (
        <s-section heading={t('settings.locationsHeading')}>
            <s-stack gap="base">
                <s-paragraph>{t('settings.locationsIntro')}</s-paragraph>
                {locations.map((l) => {
                    const isCounted = !excluded.includes(l.id);
                    return (
                        <s-checkbox
                            key={l.id}
                            label={l.name}
                            checked={isCounted || undefined}
                            // The last counted location stays: with none there would be no stock to forecast from.
                            disabled={(isCounted && counted === 1) || undefined}
                            onChange={(e) => onChange(e.currentTarget.checked ? excluded.filter((id) => id !== l.id) : [...excluded, l.id].sort((a, b) => a - b))}
                        />
                    );
                })}
                {error && <s-text tone="critical">{error}</s-text>}
            </s-stack>
        </s-section>
    );
}
