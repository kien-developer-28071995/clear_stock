import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { fieldError } from '@/lib/http';
import { useUpdateVariantSettings } from '@/features/forecasts/hooks/useForecasts';
import { FORECAST_PROFILES, type ForecastDetail, type ForecastProfile } from '@/features/forecasts/types';
import { formatNumber } from '@/utils/format';
import { SaveBar } from '@/components/ui/SaveBar';
import { useSuppliers } from '@/features/settings/hooks/useSettings';
import { NO_VALUE, fromOption, optionValue } from '@/utils/select';
import { pickVariants } from '@/lib/resourcePicker';
import { UpgradePrompt } from '@/components/ui/UpgradePrompt';
import { useEntitlements, useFeature } from '@/hooks/useEntitlements';

/** Days of own sales after which a new product stops borrowing (backend forecast.reference_full_after_days). */
const REFERENCE_FULL_AFTER_DAYS = 30;

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
    const [muted, setMuted] = useState(false);
    const [discontinued, setDiscontinued] = useState(false);
    const [cost, setCost] = useState('');
    const [profile, setProfile] = useState('');
    const [supplierSku, setSupplierSku] = useState('');
    // Similar product: local id (saved) or Shopify gid (just picked); null = none.
    const [reference, setReference] = useState<{ id: number | string; name: string } | null>(null);
    const [referencePercent, setReferencePercent] = useState('');
    const canReference = useEntitlements().reference_products;
    const referenceExists = useFeature('reference_products');

    const saved = {
        supplierId: f.settings.supplier_id ? String(f.settings.supplier_id) : '',
        leadTime: f.settings.lead_time_override?.toString() ?? '',
        safety: f.settings.safety_days?.toString() ?? '',
        minOrder: f.settings.min_order_qty?.toString() ?? '',
        pack: f.settings.pack_size?.toString() ?? '',
        minStock: f.settings.min_stock?.toString() ?? '',
        maxStock: f.settings.max_stock?.toString() ?? '',
        muted: f.settings.alerts_muted,
        discontinued: f.settings.discontinued,
        cost: f.settings.cost_override?.toString() ?? '',
        profile: f.settings.forecast_profile ?? '',
        supplierSku: f.settings.supplier_sku ?? '',
        reference: f.settings.reference_variant_id ? { id: f.settings.reference_variant_id, name: f.settings.reference_name ?? '' } : null,
        referencePercent: f.settings.reference_percent?.toString() ?? '',
    };
    const reset = () => {
        setSupplierId(saved.supplierId);
        setLeadTime(saved.leadTime);
        setSafety(saved.safety);
        setMinOrder(saved.minOrder);
        setPack(saved.pack);
        setMinStock(saved.minStock);
        setMaxStock(saved.maxStock);
        setMuted(saved.muted);
        setDiscontinued(saved.discontinued);
        setCost(saved.cost);
        setProfile(saved.profile);
        setSupplierSku(saved.supplierSku);
        setReference(saved.reference);
        setReferencePercent(saved.referencePercent);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(reset, [f.settings]);
    const current = { supplierId, leadTime, safety, minOrder, pack, minStock, maxStock, muted, discontinued, cost, profile, supplierSku, reference, referencePercent };
    const pickReference = async () => {
        const [picked] = await pickVariants();
        if (picked) setReference({ id: picked.gid, name: picked.name });
    };
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
                alerts_muted: muted,
                discontinued,
                forecast_profile: (profile || null) as ForecastProfile | null,
                supplier_sku: supplierSku.trim() || null,
                ...(cost !== saved.cost ? { cost_override: cost.trim() === '' ? null : Number(cost.replace(',', '.')) } : {}),
                // Only sent when changed: a Free shop can still save its other settings.
                ...(reference?.id !== saved.reference?.id ? { reference_variant: reference?.id ?? null } : {}),
                ...(reference ? { reference_percent: toNumberOrNull(referencePercent) } : {}),
            },
            { onSuccess: () => shopify.toast.show(t('common.saved')) },
        );

    return (
        <s-section heading={t('productSettings.heading')}>
            <SaveBar id="product-settings-save-bar" dirty={dirty} saving={update.isPending} onSave={submit} onDiscard={reset} />
            <s-stack gap="base">
                <s-select
                    label={t('table.supplier')}
                    value={optionValue(supplierId)}
                    error={fieldError(update.error, 'supplier_id')}
                    onChange={(e) => setSupplierId(fromOption(e.currentTarget.value))}
                    details={suppliers.data?.length === 0 ? t('productSettings.noSuppliers') : undefined}
                >
                    <s-option value={NO_VALUE}>{t('productSettings.noSupplier')}</s-option>
                    {suppliers.data?.map((s) => (
                        <s-option key={s.id} value={String(s.id)}>
                            {s.name}
                        </s-option>
                    ))}
                </s-select>
                <s-text-field
                    label={t('productSettings.supplierSku')}
                    details={t('productSettings.supplierSkuHelp')}
                    value={supplierSku}
                    error={fieldError(update.error, 'supplier_sku')}
                    onInput={(e) => setSupplierSku(e.currentTarget.value)}
                />
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
                        placeholder={supplier?.min_order_qty != null ? t('productSettings.fromSupplierQty', { qty: supplier.min_order_qty }) : t('productSettings.none')}
                        details={t('productSettings.minOrderHelp')}
                        value={minOrder}
                        error={fieldError(update.error, 'min_order_qty')}
                        onInput={(e) => setMinOrder(e.currentTarget.value)}
                    />
                    <s-number-field
                        label={t('productSettings.packSize')}
                        min={1}
                        placeholder={supplier?.pack_size != null ? t('productSettings.fromSupplierQty', { qty: supplier.pack_size }) : t('productSettings.none')}
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
                <s-box maxInlineSize="240px">
                    <s-number-field
                        label={t('costs.productField')}
                        min={0}
                        step={0.01}
                        placeholder={f.settings.shopify_cost != null ? t('costs.fromShopify', { cost: formatNumber(f.settings.shopify_cost, 2) }) : t('costs.noneInShopify')}
                        details={t('costs.productFieldHelp')}
                        value={cost}
                        error={fieldError(update.error, 'cost_override')}
                        onInput={(e) => setCost(e.currentTarget.value)}
                    />
                </s-box>
                <s-select
                    label={t('forecastProfile.productLabel')}
                    details={t('forecastProfile.productHelp')}
                    value={optionValue(profile)}
                    error={fieldError(update.error, 'forecast_profile')}
                    onChange={(e) => setProfile(fromOption(e.currentTarget.value))}
                >
                    <s-option value={NO_VALUE}>{t('forecastProfile.storeDefault', { name: t(`forecastProfile.${f.defaults.forecast_profile}`) })}</s-option>
                    {FORECAST_PROFILES.map((p) => (
                        <s-option key={p} value={p}>{t(`forecastProfile.${p}`)}</s-option>
                    ))}
                </s-select>
                {referenceExists && (<s-stack gap="small-200">
                    <s-text type="strong">{t('productSettings.reference')}</s-text>
                    {!canReference && <UpgradePrompt id="reference-products" plan="starter">{t('productSettings.referenceLocked')}</UpgradePrompt>}
                    <s-stack direction="inline" gap="small-200" alignItems="center">
                        <s-text>{reference?.name || t('productSettings.referenceNone')}</s-text>
                        <s-button disabled={!canReference || undefined} onClick={pickReference}>
                            {reference ? t('productSettings.referenceChange') : t('productSettings.referencePick')}
                        </s-button>
                        {reference && (
                            <s-button variant="tertiary" onClick={() => setReference(null)}>
                                {t('productSettings.referenceClear')}
                            </s-button>
                        )}
                    </s-stack>
                    {reference && (
                        <s-box maxInlineSize="240px">
                            <s-number-field
                                label={t('productSettings.referencePercent')}
                                suffix="%"
                                min={10}
                                max={500}
                                placeholder="100"
                                value={referencePercent}
                                error={fieldError(update.error, 'reference_percent')}
                                onInput={(e) => setReferencePercent(e.currentTarget.value)}
                            />
                        </s-box>
                    )}
                    {fieldError(update.error, 'reference_variant') && <s-text tone="critical">{fieldError(update.error, 'reference_variant')}</s-text>}
                    <s-text color="subdued">{t('productSettings.referenceHelp', { count: REFERENCE_FULL_AFTER_DAYS })}</s-text>
                </s-stack>)}
                <s-checkbox
                    label={t('productSettings.discontinued')}
                    details={t('productSettings.discontinuedHelp')}
                    checked={discontinued || undefined}
                    onChange={(e) => setDiscontinued(e.currentTarget.checked)}
                />
                <s-checkbox
                    label={t('productSettings.muteAlerts')}
                    details={t('productSettings.muteAlertsHelp')}
                    checked={muted || undefined}
                    onChange={(e) => setMuted(e.currentTarget.checked)}
                />
            </s-stack>
        </s-section>
    );
}
