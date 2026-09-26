import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { fieldError } from '@/lib/http';
import { useUpdateVariantSettings } from '@/features/forecasts/hooks/useForecasts';
import type { ForecastDetail } from '@/features/forecasts/types';
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

    useEffect(() => {
        setSupplierId(f.settings.supplier_id ? String(f.settings.supplier_id) : '');
        setLeadTime(f.settings.lead_time_override?.toString() ?? '');
        setSafety(f.settings.safety_days?.toString() ?? '');
    }, [f.settings]);

    const supplier = suppliers.data?.find((s) => String(s.id) === supplierId);
    const leadFallback = supplier?.lead_time_days ?? f.defaults.lead_time_days;

    const submit = () =>
        update.mutate(
            {
                supplier_id: supplierId ? Number(supplierId) : null,
                lead_time_override: toNumberOrNull(leadTime),
                safety_days: toNumberOrNull(safety),
            },
            { onSuccess: () => shopify.toast.show(t('common.saved')) },
        );

    return (
        <s-section heading={t('productSettings.heading')}>
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
                <s-stack direction="inline">
                    <s-button onClick={submit} loading={update.isPending || undefined}>
                        {t('productSettings.save')}
                    </s-button>
                </s-stack>
            </s-stack>
        </s-section>
    );
}
