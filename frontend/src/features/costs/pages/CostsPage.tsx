import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { SaveBar } from '@/components/ui/SaveBar';
import { ApiError, errorMessage, fieldError } from '@/lib/http';
import { useCosts, useImportCosts, useUpdateCosts } from '@/features/costs/hooks/useCosts';
import { useShop } from '@/features/shop/hooks/useShop';
import { formatMoney, formatNumber } from '@/utils/format';

/**
 * Unit costs for products whose Shopify cost is missing (or not the real landed cost). They make
 * the money figures work: stock value, cash tied up, purchase order and plan totals. Shopify is not changed.
 */
export function CostsPage() {
    const { t } = useTranslation();
    const currency = useShop().data?.currency ?? null;
    const [missing, setMissing] = useState(true);
    const [search, setSearch] = useState('');
    const [term, setTerm] = useState('');
    const { data, error, isFetching, refetch } = useCosts({ missing, search: term });
    const update = useUpdateCosts();
    const importCsv = useImportCosts();
    // Edited costs by variant id ('' = clear the app cost).
    const [edits, setEdits] = useState<Record<number, string>>({});
    useEffect(() => setEdits({}), [missing, term]);

    const dirty = Object.keys(edits).length > 0;
    const save = () => {
        const items = Object.entries(edits).map(([id, v]) => ({ variant_id: Number(id), cost: v.trim() === '' ? null : Number(v.replace(',', '.')) }));
        update.mutate(items, {
            onSuccess: (r) => {
                shopify.toast.show(t('costs.saved', { count: r.updated }));
                setEdits({});
            },
        });
    };
    const upload = (file: File | undefined) => {
        if (!file) return;
        importCsv.mutate(file, {
            onSuccess: (r) => shopify.toast.show(t('costs.imported', { count: r.updated })),
        });
    };
    const importError = importCsv.error instanceof ApiError ? fieldError(importCsv.error, 'file') ?? errorMessage(importCsv.error) : undefined;

    return (
        <s-page heading={t('nav.costs')}>
            <s-link slot="breadcrumb-actions" href="/settings">{t('nav.settings')}</s-link>
            <SaveBar id="costs-save-bar" dirty={dirty} saving={update.isPending} onSave={save} onDiscard={() => setEdits({})} />
            {error && <ErrorBanner error={error} onRetry={() => refetch()} />}

            <s-section>
                <s-stack gap="base">
                    <s-paragraph>{t('costs.intro')}</s-paragraph>
                    {data && (
                        <s-text type="strong">
                            {data.counts.missing > 0 ? t('costs.missingCount', { count: data.counts.missing, total: data.counts.tracked }) : t('costs.allSet')}
                        </s-text>
                    )}
                </s-stack>
            </s-section>

            <s-section heading={t('costs.importHeading')}>
                <s-stack gap="base">
                    <s-text color="subdued">{t('costs.importHelp')}</s-text>
                    <s-drop-zone label={t('costs.importLabel')} accessibilityLabel={t('costs.importLabel')} accept=".csv,text/csv" error={importError} onChange={(e) => upload(e.currentTarget.files?.[0])} />
                    {importCsv.isPending && <s-spinner accessibilityLabel={t('common.loading')} />}
                    {importCsv.data && (
                        <s-banner tone={importCsv.data.updated > 0 ? 'success' : 'warning'}>
                            {t('costs.importResult', { count: importCsv.data.updated })}
                            {importCsv.data.unmatched > 0 && ` ${t('costs.importUnmatched', { count: importCsv.data.unmatched, examples: importCsv.data.unmatched_examples.join(', ') })}`}
                            {importCsv.data.invalid > 0 && ` ${t('costs.importInvalid', { count: importCsv.data.invalid })}`}
                        </s-banner>
                    )}
                </s-stack>
            </s-section>

            <s-section padding="none">
                <s-box padding="base">
                    <s-grid gridTemplateColumns="@container (inline-size > 500px) 1fr auto, 1fr" gap="base" alignItems="end">
                        <s-search-field
                            label={t('products.search')}
                            labelAccessibilityVisibility="exclusive"
                            placeholder={t('products.searchPlaceholder')}
                            value={search}
                            onInput={(e) => setSearch(e.currentTarget.value)}
                            onChange={() => setTerm(search.trim())}
                        />
                        <s-checkbox label={t('costs.missingOnly')} checked={missing || undefined} onChange={(e) => setMissing(e.currentTarget.checked)} />
                    </s-grid>
                </s-box>
                {data && data.items.length === 0 ? (
                    <s-box padding="base"><s-paragraph>{missing ? t('costs.noneMissing') : t('products.noMatch')}</s-paragraph></s-box>
                ) : (
                    <s-table loading={isFetching || undefined}>
                        <s-table-header-row>
                            <s-table-header listSlot="primary">{t('table.product')}</s-table-header>
                            <s-table-header format="currency">{t('costs.shopifyCost')}</s-table-header>
                            <s-table-header>{t('costs.appCost')}</s-table-header>
                        </s-table-header-row>
                        <s-table-body>
                            {data?.items.map((row) => (
                                <s-table-row key={row.variant_id}>
                                    <s-table-cell>
                                        <s-stack gap="small-100">
                                            <s-link href={`/products/${row.variant_id}`}>{row.name}</s-link>
                                            {row.sku && <s-text color="subdued">{row.sku}</s-text>}
                                        </s-stack>
                                    </s-table-cell>
                                    <s-table-cell>{row.shopify_cost === null ? '—' : formatMoney(row.shopify_cost, currency)}</s-table-cell>
                                    <s-table-cell>
                                        <s-box maxInlineSize="160px">
                                            <s-number-field
                                                label={t('costs.appCostFor', { name: row.name })}
                                                labelAccessibilityVisibility="exclusive"
                                                min={0}
                                                step={0.01}
                                                placeholder={row.shopify_cost === null ? '' : formatNumber(row.shopify_cost, 2)}
                                                value={edits[row.variant_id] ?? (row.app_cost === null ? '' : String(row.app_cost))}
                                                onInput={(e) => {
                                                    const value = e.currentTarget.value;
                                                    setEdits((cur) => ({ ...cur, [row.variant_id]: value }));
                                                }}
                                            />
                                        </s-box>
                                    </s-table-cell>
                                </s-table-row>
                            ))}
                        </s-table-body>
                    </s-table>
                )}
                {data && data.items.length >= data.limit && (
                    <s-box padding="base"><s-text color="subdued">{t('costs.limit', { count: data.limit })}</s-text></s-box>
                )}
            </s-section>
        </s-page>
    );
}
