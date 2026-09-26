import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import { useTranslation } from 'react-i18next';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { ApiError, fieldError } from '@/lib/http';
import { ColumnMapping } from '@/features/imports/components/ColumnMapping';
import { useApplyImport, useImportPreview } from '@/features/imports/hooks/useImport';
import type { ImportMapping, ImportSupplier } from '@/features/imports/types';
import { formatNumber } from '@/utils/format';

/** Lead time shown for a supplier: the one already set in the app wins over the measured one. */
const initialLeadTime = (s: ImportSupplier) => String(s.current_lead_time_days ?? s.lead_time_days ?? '');

/**
 * Coming from Stocky (or another inventory app): upload purchase order CSVs, check
 * what we found, then create suppliers, link products and set lead times in one go.
 */
export function PurchaseOrderImportPage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const preview = useImportPreview();
    const apply = useApplyImport();
    const [files, setFiles] = useState<File[]>([]);
    const [leadTimes, setLeadTimes] = useState<Record<string, string>>({});
    const [replaceExisting, setReplaceExisting] = useState(false);
    const data = preview.data;

    useEffect(() => {
        if (data) setLeadTimes(Object.fromEntries(data.suppliers.map((s) => [s.name, initialLeadTime(s)])));
    }, [data]);

    const choose = (picked: File[]) => {
        if (picked.length === 0) return;
        setFiles(picked);
        preview.mutate({ files: picked, mapping: null });
    };
    const remap = (mapping: ImportMapping) => preview.mutate({ files, mapping });

    const submit = () =>
        data &&
        apply.mutate(
            {
                files,
                mapping: data.mapping,
                replaceExisting,
                suppliers: data.suppliers.map((s) => ({
                    name: s.name,
                    lead_time_days: leadTimes[s.name] === '' || leadTimes[s.name] === undefined ? null : Number(leadTimes[s.name]),
                })),
            },
            {
                onSuccess: (r) => {
                    shopify.toast.show(t('import.done', { suppliers: r.suppliers_created, count: r.products_assigned }));
                    navigate('/suppliers');
                },
            },
        );

    const fileError = fieldError(preview.error, 'files') ?? fieldError(preview.error, 'files.0');
    const otherError = preview.error instanceof ApiError && preview.error.status !== 422 ? preview.error : null;
    const ready = data && data.missing.length === 0 && data.suppliers.length > 0;

    return (
        <s-page heading={t('import.heading')}>
            <s-link slot="breadcrumb-actions" href="/suppliers">{t('nav.suppliers')}</s-link>

            <s-section>
                <s-stack gap="base">
                    <s-paragraph>{t('import.intro')}</s-paragraph>
                    <s-ordered-list>
                        <s-list-item>{t('import.stepExport')}</s-list-item>
                        <s-list-item>{t('import.stepUpload')}</s-list-item>
                        <s-list-item>{t('import.stepCheck')}</s-list-item>
                    </s-ordered-list>
                    <s-text color="subdued">{t('import.otherApps')}</s-text>
                    <s-drop-zone
                        label={t('import.dropLabel')}
                        accessibilityLabel={t('import.dropLabel')}
                        accept=".csv,text/csv"
                        multiple
                        error={fileError}
                        onChange={(e) => choose(Array.from(e.currentTarget.files ?? []))}
                    />
                    {files.length > 0 && (
                        <s-text color="subdued">{t('import.filesChosen', { count: files.length, names: files.map((f) => f.name).join(', ') })}</s-text>
                    )}
                    {preview.isPending && <s-spinner accessibilityLabel={t('common.loading')} />}
                </s-stack>
            </s-section>

            {otherError && <ErrorBanner error={otherError} />}
            {apply.error && <ErrorBanner error={apply.error} />}

            {data && (
                <>
                    <s-section heading={t('import.columnsHeading')}>
                        <s-stack gap="base">
                            {data.missing.length > 0 ? (
                                <s-banner tone="warning">
                                    {t('import.missing', { fields: data.missing.map((f) => t(`import.required.${f}`)).join(', ') })}
                                </s-banner>
                            ) : (
                                <s-text color="subdued">{t('import.columnsHelp')}</s-text>
                            )}
                            <ColumnMapping columns={data.columns} mapping={data.mapping} onChange={remap} />
                        </s-stack>
                    </s-section>

                    {data.missing.length === 0 && (
                        <>
                            <s-section heading={t('import.suppliersHeading')}>
                                <s-stack gap="base">
                                    <s-text color="subdued">
                                        {t('import.summary', { rows: formatNumber(data.rows), orders: formatNumber(data.purchase_orders), count: data.suppliers.length })}
                                    </s-text>
                                    <s-table>
                                        <s-table-header-row>
                                            <s-table-header listSlot="primary">{t('table.supplier')}</s-table-header>
                                            <s-table-header format="numeric">{t('nav.products')}</s-table-header>
                                            <s-table-header format="numeric">{t('import.orders')}</s-table-header>
                                            <s-table-header>{t('suppliers.leadTime')}</s-table-header>
                                        </s-table-header-row>
                                        <s-table-body>
                                            {data.suppliers.map((s) => (
                                                <s-table-row key={s.name}>
                                                    <s-table-cell>
                                                        <s-stack direction="inline" gap="small-200" alignItems="center">
                                                            <s-text type="strong">{s.name}</s-text>
                                                            <s-badge tone={s.existing ? undefined : 'success'}>
                                                                {s.existing ? t('import.existing') : t('import.new')}
                                                            </s-badge>
                                                        </s-stack>
                                                    </s-table-cell>
                                                    <s-table-cell>{s.products}</s-table-cell>
                                                    <s-table-cell>{s.purchase_orders}</s-table-cell>
                                                    <s-table-cell>
                                                        <s-number-field
                                                            label={t('suppliers.leadTime')}
                                                            labelAccessibilityVisibility="exclusive"
                                                            suffix={t('common.daysSuffix')}
                                                            min={0}
                                                            max={365}
                                                            placeholder={t('suppliers.storeDefault')}
                                                            value={leadTimes[s.name] ?? ''}
                                                            details={
                                                                s.lead_time_days !== null
                                                                    ? t('import.measured', { days: s.lead_time_days, count: s.lead_time_samples })
                                                                    : t('import.notMeasured')
                                                            }
                                                            onInput={(e) => setLeadTimes({ ...leadTimes, [s.name]: e.currentTarget.value })}
                                                        />
                                                    </s-table-cell>
                                                </s-table-row>
                                            ))}
                                        </s-table-body>
                                    </s-table>
                                </s-stack>
                            </s-section>

                            <s-section heading={t('nav.products')}>
                                <s-stack gap="base">
                                    <s-paragraph>{t('import.matched', { count: data.products.matched })}</s-paragraph>
                                    {data.products.unmatched > 0 && (
                                        <s-banner tone="info">
                                            {t('import.unmatched', { count: data.products.unmatched, samples: data.products.unmatched_samples.join(', ') })}
                                        </s-banner>
                                    )}
                                    {data.products.with_supplier > 0 && (
                                        <s-checkbox
                                            label={t('import.replace', { count: data.products.with_supplier })}
                                            checked={replaceExisting || undefined}
                                            onChange={(e) => setReplaceExisting(e.currentTarget.checked)}
                                        />
                                    )}
                                </s-stack>
                            </s-section>
                        </>
                    )}

                    <s-stack direction="inline" gap="base">
                        <s-button variant="primary" onClick={submit} disabled={!ready || undefined} loading={apply.isPending || undefined}>
                            {t('import.apply')}
                        </s-button>
                    </s-stack>
                </>
            )}
        </s-page>
    );
}
