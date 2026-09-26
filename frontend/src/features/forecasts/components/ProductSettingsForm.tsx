import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { fieldError } from '@/lib/http';
import { useUpdateVariantSettings } from '@/features/forecasts/hooks/useForecasts';
import type { ForecastDetail } from '@/features/forecasts/types';
import { formatNumber } from '@/utils/format';
import { SaveBar } from '@/components/ui/SaveBar';
import { useSuppliers } from '@/features/settings/hooks/useSettings';

const toNumberOrNull = (v: string) => (v.trim() === '' ? null : Number(v));

/** Permanent per-product settings: supplier, lead time and safety days (empty = use the default). */
export function ProductSettingsForm({ f }: { f: ForecastDetail }) {
    const { t } = useTranslation();
    const suppliers = useSuppliers();
    const update = useUpdateVariantSettings(f.variant_id);
    const [supplierId, setSupplierId] = useState('');
    const [leadTime, setLeadTime] = useState('');
    const [safety, setSafety] = useState('');
    const [minOrder, setMinOrder] = useState('');
    const [pack, setPack] = useState('');
    const [minStock, setMinStock] = useState('');
    const [maxStock, setMaxStock] = useState('');

    const saved = {
        supplierId: f.settings.supplier_id ? String(f.settings.supplier_id) : '',
        leadTime: f.settings.lead_time_override?.toString() ?? '',
        safety: f.settings.safety_days?.toString() ?? '',
        minOrder: f.settings.min_order_qty?.toString() ?? '',
        pack: f.settings.pack_size?.toString() ?? '',
        minStock: f.settings.min_stock?.toString() ?? '',
        maxStock: f.settings.max_stock?.toString() ?? '',
    };
    const reset = () => {
        setSupplierId(saved.supplierId);
        setLeadTime(saved.leadTime);
        setSafety(saved.safety);
        setMinOrder(saved.minOrder);
        setPack(saved.pack);
        setMinStock(saved.minStock);
        setMaxStock(saved.maxStock);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(reset, [f.settings]);
    const current = { supplierId, leadTime, safety, minOrder, pack, minStock, maxStock };
    const dirty = JSON.stringify(current) !== JSON.stringify(saved);

    const supplier = suppliers.data?.find((s) => String(s.id) === supplierId);
    const leadFallback = supplier?.lead_time_days ?? f.defaults.lead_time_days;

    const submit = () =>
        update.mutate(
            {
                supplier_id: supplierId ? Number(supplierId) : null,
                lead_time_override: toNumberOrNull(leadTime),
                safety_days: toNumberOrNull(safety),
                min_order_qty: toNumberOrNull(minOrder),
                pack_size: toNumberOrNull(pack),
                min_stock: toNumberOrNull(minStock),
                max_stock: toNumberOrNull(maxStock),
            },
            { onSuccess: () => shopify.toast.show(t('common.saved')) },
        );

    return (
        <s-section heading={t('productSettings.heading')}>
            <SaveBar id="product-settings-save-bar" dirty={dirty} saving={update.isPending} onSave={submit} onDiscard={reset} />
            <s-stack gap="base">
                <s-select
                    label={t('table.supplier')}
                    value={supplierId}
                    error={fieldError(update.error, 'supplier_id')}
                    onChange={(e) => setSupplierId(e.currentTarget.value)}
                    details={suppliers.data?.length === 0 ? t('productSettings.noSuppliers') : undefined}
                >
                    <s-option value="">{t('productSettings.noSupplier')}</s-option>
                    {suppliers.data?.map((s) => (
                        <s-option key={s.id} value={String(s.id)}>
                            {s.name}
                        </s-option>
                    ))}
                </s-select>
                <s-grid gridTemplateColumns="@container (inline-size > 500px) 1fr 1fr, 1fr" gap="base">
                    <s-number-field
                        label={t('productSettings.leadTime')}
                        suffix={t('common.daysSuffix')}
                        min={0}
                        placeholder={supplier?.lead_time_days != null ? t('productSettings.fromSupplier', { days: leadFallback }) : t('productSettings.fromDefault', { days: leadFallback })}
                        value={leadTime}
                        error={fieldError(update.error, 'lead_time_override')}
                        onInput={(e) => setLeadTime(e.currentTarget.value)}
                    />
                    <s-number-field
                        label={t('productSettings.safety')}
                        suffix={t('common.daysSuffix')}
                        min={0}
                        placeholder={t('productSettings.fromDefault', { days: f.defaults.safety_days })}
                        details={t('productSettings.safetyHelp')}
                        value={safety}
                        error={fieldError(update.error, 'safety_days')}
                        onInput={(e) => setSafety(e.currentTarget.value)}
                    />
                </s-grid>
                <s-grid gridTemplateColumns="@container (inline-size > 500px) 1fr 1fr, 1fr" gap="base">
                    <s-number-field
                        label={t('productSettings.minOrder')}
                        min={1}
                        placeholder={t('productSettings.none')}
                        details={t('productSettings.minOrderHelp')}
                        value={minOrder}
                        error={fieldError(update.error, 'min_order_qty')}
                        onInput={(e) => setMinOrder(e.currentTarget.value)}
                    />
                    <s-number-field
                        label={t('productSettings.packSize')}
                        min={1}
                        placeholder={t('productSettings.none')}
                        details={t('productSettings.packSizeHelp')}
                        value={pack}
                        error={fieldError(update.error, 'pack_size')}
                        onInput={(e) => setPack(e.currentTarget.value)}
                    />
                </s-grid>
                <s-grid gridTemplateColumns="@container (inline-size > 500px) 1fr 1fr, 1fr" gap="base">
                    <s-number-field
                        label={t('productSettings.minStock')}
                        min={0}
                        placeholder={t('productSettings.fromForecast', { qty: formatNumber(f.explanation?.reorder.computed_point ?? f.reorder_point, 0) })}
                        details={t('productSettings.minStockHelp')}
                        value={minStock}
                        error={fieldError(update.error, 'min_stock')}
                        onInput={(e) => setMinStock(e.currentTarget.value)}
                    />
                    <s-number-field
                        label={t('productSettings.maxStock')}
                        min={1}
                        placeholder={t('productSettings.fromForecastShort')}
                        details={t('productSettings.maxStockHelp')}
                        value={maxStock}
                        error={fieldError(update.error, 'max_stock')}
                        onInput={(e) => setMaxStock(e.currentTarget.value)}
                    />
                </s-grid>
            </s-stack>
        </s-section>
    );
}
