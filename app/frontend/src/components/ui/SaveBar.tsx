import { useEffect, type ElementType } from 'react';
import { useTranslation } from 'react-i18next';

// App Bridge renders <ui-save-bar> in the admin chrome; its children are plain <button>s.
const UiSaveBar = 'ui-save-bar' as unknown as ElementType;
const primary = { variant: 'primary' } as object;

interface Props {
    id: string;
    dirty: boolean;
    saving: boolean;
    onSave: () => void;
    onDiscard: () => void;
}

/**
 * Contextual save bar (Built for Shopify 4.1.5): appears in the admin while a form
 * has unsaved changes, with Save and Discard. Leaving the page asks for confirmation.
 */
export function SaveBar({ id, dirty, saving, onSave, onDiscard }: Props) {
    const { t } = useTranslation();

    useEffect(() => {
        if (dirty) void shopify.saveBar?.show(id);
        else void shopify.saveBar?.hide(id);
    }, [id, dirty]);

    useEffect(() => () => void shopify.saveBar?.hide(id), [id]);

    return (
        <UiSaveBar id={id}>
            <button {...primary} onClick={onSave} loading={saving ? '' : undefined}>
                {t('common.save')}
            </button>
            <button onClick={onDiscard} disabled={saving || undefined}>
                {t('common.discard')}
            </button>
        </UiSaveBar>
    );
}
