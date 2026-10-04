import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { errorMessage } from '@/lib/http';
import type { ForecastDetail } from '@/features/forecasts/types';
import { useMakeMainSupplier, useSetAlternates } from '@/features/forecasts/hooks/useForecasts';
import { useSuppliers } from '@/features/settings/hooks/useSettings';
import { useShop } from '@/features/shop/hooks/useShop';
import { formatMoney } from '@/utils/format';
import { NO_VALUE, fromOption, optionValue } from '@/utils/select';

const numberOrNull = (v: string) => (v.trim() === '' ? null : Number(v.replace(',', '.')));

/**
 * Other suppliers this product can be bought from, each with its own cost, lead time and code.
 * "Use as supplier" swaps it with the current one (which is kept in this list).
 */
export function AlternateSuppliers({ f }: { f: ForecastDetail }) {
    const { t } = useTranslation();
    const suppliers = useSuppliers();
    const save = useSetAlternates(f.variant_id);
    const makeMain = useMakeMainSupplier(f.variant_id);
    const currency = useShop().data?.currency ?? null;
    const [supplierId, setSupplierId] = useState('');
    const [cost, setCost] = useState('');
    const [lead, setLead] = useState('');
    const [sku, setSku] = useState('');

    const list = f.alternate_suppliers;
    const taken = new Set([f.settings.supplier_id, ...list.map((a) => a.supplier_id)]);
    const options = suppliers.data?.filter((s) => !taken.has(s.id)) ?? [];
    // Nothing to offer and nothing saved: the section would only be noise.
    if (list.length === 0 && options.length === 0) return null;

    const toast = { onError: (e: unknown) => shopify.toast.show(errorMessage(e), { isError: true }) };
    const stripName = list.map(({ name: _name, ...rest }) => rest);
    const add = () =>
        save.mutate([...stripName, { supplier_id: Number(supplierId), unit_cost: numberOrNull(cost), lead_time_days: numberOrNull(lead), supplier_sku: sku.trim() || null }], {
            ...toast,
            onSuccess: () => { setSupplierId(''); setCost(''); setLead(''); setSku(''); shopify.toast.show(t('common.saved')); },
        });

    return (
        <s-section heading={t('alternates.heading')}>
            <s-stack gap="base">
                <s-paragraph>{t('alternates.intro')}</s-paragraph>
                {list.length > 0 && (
                    <s-table>
                        <s-table-header-row>
                            <s-table-header listSlot="primary">{t('table.supplier')}</s-table-header>
                            <s-table-header format="currency">{t('alternates.cost')}</s-table-header>
                            <s-table-header format="numeric">{t('suppliers.leadTime')}</s-table-header>
                            <s-table-header>{t('productSettings.supplierSku')}</s-table-header>
                            <s-table-header>{t('common.actions')}</s-table-header>
                        </s-table-header-row>
                        <s-table-body>
                            {list.map((a) => (
                                <s-table-row key={a.supplier_id}>
                                    <s-table-cell>{a.name}</s-table-cell>
                                    <s-table-cell>{a.unit_cost === null ? '—' : formatMoney(a.unit_cost, currency)}</s-table-cell>
                                    <s-table-cell>{a.lead_time_days === null ? '—' : t('common.dayCount', { count: a.lead_time_days })}</s-table-cell>
                                    <s-table-cell>{a.supplier_sku ?? '—'}</s-table-cell>
                                    <s-table-cell>
                                        <s-button-group>
                                            <s-button
                                                slot="secondary-actions"
                                                loading={makeMain.isPending || undefined}
                                                onClick={() => makeMain.mutate(a.supplier_id, { ...toast, onSuccess: () => shopify.toast.show(t('alternates.switched', { name: a.name })) })}
                                            >
                                                {t('alternates.makeMain')}
                                            </s-button>
                                            <s-button
                                                slot="secondary-actions"
                                                tone="critical"
                                                onClick={() => save.mutate(stripName.filter((x) => x.supplier_id !== a.supplier_id), toast)}
                                            >
                                                {t('common.remove')}
                                            </s-button>
                                        </s-button-group>
                                    </s-table-cell>
                                </s-table-row>
                            ))}
                        </s-table-body>
                    </s-table>
                )}
                {options.length > 0 && (
                    <s-grid gridTemplateColumns="@container (inline-size > 600px) 2fr 1fr 1fr 1fr auto, 1fr" gap="small-200" alignItems="end">
                        <s-select label={t('alternates.add')} value={optionValue(supplierId)} onChange={(e) => setSupplierId(fromOption(e.currentTarget.value))}>
                            <s-option value={NO_VALUE}>{t('alternates.choose')}</s-option>
                            {options.map((s) => (
                                <s-option key={s.id} value={String(s.id)}>{s.name}</s-option>
                            ))}
                        </s-select>
                        <s-number-field label={t('alternates.cost')} min={0} step={0.01} value={cost} onInput={(e) => setCost(e.currentTarget.value)} />
                        <s-number-field label={t('suppliers.leadTime')} min={0} suffix={t('common.daysSuffix')} value={lead} onInput={(e) => setLead(e.currentTarget.value)} />
                        <s-text-field label={t('productSettings.supplierSku')} value={sku} onInput={(e) => setSku(e.currentTarget.value)} />
                        <s-button disabled={!supplierId || undefined} loading={save.isPending || undefined} onClick={add}>{t('alternates.addButton')}</s-button>
                    </s-grid>
                )}
            </s-stack>
        </s-section>
    );
}
