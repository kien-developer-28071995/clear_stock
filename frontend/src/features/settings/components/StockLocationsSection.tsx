import { useTranslation } from 'react-i18next';
import { errorMessage } from '@/lib/http';
import { useExcludeLocations, useStockLocations } from '@/features/settings/hooks/useSettings';

/**
 * Which locations' stock the forecasts count. Shown with two or more locations: a returns
 * or damaged-goods location would otherwise make products look better stocked than they are.
 * Saved at once (a switch per location), forecasts are recomputed in the background.
 */
export function StockLocationsSection() {
    const { t } = useTranslation();
    const { data } = useStockLocations();
    const exclude = useExcludeLocations();
    if (!data || data.length < 2) return null;

    const counted = data.filter((l) => !l.excluded).length;
    const toggle = (id: number, count: boolean) =>
        exclude.mutate(
            data.filter((l) => (l.id === id ? !count : l.excluded)).map((l) => l.id),
            {
                onSuccess: () => shopify.toast.show(t('settings.locationsSaved')),
                onError: (e) => shopify.toast.show(errorMessage(e), { isError: true }),
            },
        );

    return (
        <s-section heading={t('settings.locationsHeading')}>
            <s-stack gap="base">
                <s-paragraph>{t('settings.locationsIntro')}</s-paragraph>
                {data.map((l) => (
                    <s-checkbox
                        key={l.id}
                        label={l.name}
                        checked={!l.excluded || undefined}
                        // The last counted location can't be switched off: there would be no stock to forecast from.
                        disabled={exclude.isPending || (!l.excluded && counted === 1) || undefined}
                        onChange={(e) => toggle(l.id, e.currentTarget.checked)}
                    />
                ))}
            </s-stack>
        </s-section>
    );
}
