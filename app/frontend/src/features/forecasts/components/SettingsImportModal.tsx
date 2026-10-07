import { useState, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import type { ModalElement } from '@/hooks/useModal';
import { useImportSettings } from '@/features/forecasts/hooks/useForecasts';
import { ApiError, errorMessage, fieldError } from '@/lib/http';

/** Setting names as the change history shows them. */
const fieldKey = (field: string) => (field === 'supplier_id' ? 'supplier' : field);

/**
 * Reorder settings of many products from a CSV (a row per product, found by SKU or barcode).
 * The file is read first and what it would change is shown; nothing is saved before "Apply".
 */
export function SettingsImportModal({ id, modalRef }: { id: string; modalRef: RefObject<ModalElement | null> }) {
    const { t } = useTranslation();
    const run = useImportSettings();
    const [file, setFile] = useState<File | null>(null);
    const preview = run.data && !run.data.applied ? run.data : null;

    const reset = () => {
        setFile(null);
        run.reset();
    };
    const choose = (picked: File | undefined) => {
        if (!picked) return;
        setFile(picked);
        run.mutate({ file: picked, apply: false });
    };
    const apply = () => {
        if (!file) return;
        run.mutate(
            { file, apply: true },
            {
                onSuccess: (r) => {
                    shopify.toast.show(t('products.import.done', { count: r.products }));
                    modalRef.current?.hideOverlay();
                },
            },
        );
    };
    const error = run.error instanceof ApiError ? fieldError(run.error, 'file') ?? errorMessage(run.error) : run.error ? t('errors.generic') : undefined;

    return (
        <s-modal ref={modalRef} id={id} heading={t('products.import.heading')} onAfterHide={reset}>
            <s-stack gap="base">
                <s-paragraph>{t('products.import.help')}</s-paragraph>
                <s-drop-zone label={t('products.import.upload')} accessibilityLabel={t('products.import.upload')} accept=".csv,text/csv" error={error} onChange={(e) => choose(e.currentTarget.files?.[0])} />
                {run.isPending && <s-spinner accessibilityLabel={t('common.loading')} />}
                {preview && (
                    <s-banner tone={preview.products > 0 ? 'info' : 'warning'}>
                        <s-stack gap="small-200">
                            <s-text type="strong">{t('products.import.preview', { count: preview.products })}</s-text>
                            <s-text>{t('products.import.fields', { fields: preview.fields.map((f) => t(`history.fields.${fieldKey(f)}`, { defaultValue: f })).join(', ') })}</s-text>
                            {preview.unmatched > 0 && <s-text>{t('products.import.unmatched', { count: preview.unmatched, examples: preview.unmatched_examples.join(', ') })}</s-text>}
                            {preview.invalid > 0 && (
                                <s-text>
                                    {t('products.import.invalid', {
                                        count: preview.invalid,
                                        examples: preview.invalid_examples.map((e) => `${e.sku} (${t(`history.fields.${fieldKey(e.field)}`, { defaultValue: e.field })})`).join(', '),
                                    })}
                                </s-text>
                            )}
                            {preview.unknown_suppliers.length > 0 && <s-text>{t('products.import.unknownSuppliers', { names: preview.unknown_suppliers.join(', ') })}</s-text>}
                        </s-stack>
                    </s-banner>
                )}
            </s-stack>
            <s-button slot="primary-action" variant="primary" onClick={apply} loading={(run.isPending && run.variables?.apply) || undefined} disabled={!preview || preview.products === 0 || undefined}>
                {t('products.import.apply')}
            </s-button>
            <s-button slot="secondary-actions" commandFor={id} command="--hide">
                {t('common.cancel')}
            </s-button>
        </s-modal>
    );
}
