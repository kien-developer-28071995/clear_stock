import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { errorMessage, fieldError } from '@/lib/http';
import { useDeleteView, useSaveView, useSavedViews } from '@/features/forecasts/hooks/useForecasts';

/** Filters that make up a view (backend SavedViewService::FILTERS). */
const VIEW_FILTERS = ['status', 'sort', 'vendor', 'product_type', 'abc', 'trend', 'location_id'];

interface Props {
    /** Current filters of the list (URL params). */
    current: Record<string, string>;
    onApply: (filters: Record<string, string>) => void;
}

/** Named sets of list filters: apply one with a click, save the current filters under a name. */
export function SavedViews({ current, onApply }: Props) {
    const { t } = useTranslation();
    const views = useSavedViews();
    const save = useSaveView();
    const remove = useDeleteView();
    const [name, setName] = useState('');

    const filters = Object.fromEntries(Object.entries(current).filter(([k, v]) => VIEW_FILTERS.includes(k) && v !== ''));
    const same = (a: Record<string, string>) => JSON.stringify(Object.entries(a).sort()) === JSON.stringify(Object.entries(filters).sort());
    const submit = () =>
        save.mutate(
            { name: name.trim(), filters },
            { onSuccess: () => { setName(''); shopify.toast.show(t('views.saved')); }, onError: (e) => shopify.toast.show(errorMessage(e), { isError: true }) },
        );

    return (
        <s-section>
            <s-stack direction="inline" gap="small-200" alignItems="end">
                {views.data?.map((v) => (
                    <s-button-group key={v.id}>
                        <s-button slot="secondary-actions" variant={same(v.filters) ? 'primary' : 'secondary'} onClick={() => onApply(v.filters)}>{v.name}</s-button>
                        <s-button slot="secondary-actions" icon="x" accessibilityLabel={t('views.delete', { name: v.name })} onClick={() => remove.mutate(v.id)} />
                    </s-button-group>
                ))}
                <s-box minInlineSize="220px">
                    <s-text-field
                        label={t('views.name')}
                        labelAccessibilityVisibility="exclusive"
                        placeholder={t('views.namePlaceholder')}
                        value={name}
                        error={fieldError(save.error, 'name')}
                        onInput={(e) => setName(e.currentTarget.value)}
                    />
                </s-box>
                <s-button disabled={name.trim() === '' || undefined} loading={save.isPending || undefined} onClick={submit}>{t('views.save')}</s-button>
            </s-stack>
        </s-section>
    );
}
