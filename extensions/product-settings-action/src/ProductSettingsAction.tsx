import { render } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { api, ApiError } from '../../shared/api';

interface Supplier {
    id: number;
    name: string;
}

// Polaris options with value="" submit their label: use explicit values.
const KEEP = 'keep';
const NONE = 'none';

export default async () => {
    render(<ProductSettingsAction />, document.body);
};

function ProductSettingsAction() {
    const { i18n, data, close } = shopify;
    const t = i18n.translate;
    const productIds = data.selected.map((s) => s.id);

    const [suppliers, setSuppliers] = useState<Supplier[] | null>(null);
    const [supplier, setSupplier] = useState(KEEP);
    const [leadTime, setLeadTime] = useState('');
    const [safety, setSafety] = useState('');
    const [minOrder, setMinOrder] = useState('');
    const [pack, setPack] = useState('');
    const [discontinued, setDiscontinued] = useState(KEEP);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [updated, setUpdated] = useState<number | null>(null);

    useEffect(() => {
        api<Supplier[]>('suppliers')
            .then(setSuppliers)
            .catch(() => setSuppliers([]));
    }, []);

    const apply = async () => {
        const settings: Record<string, number | boolean | null> = {};
        if (supplier !== KEEP) settings.supplier_id = supplier === NONE ? null : Number(supplier);
        if (leadTime.trim() !== '') settings.lead_time_override = Number(leadTime);
        if (safety.trim() !== '') settings.safety_days = Number(safety);
        if (minOrder.trim() !== '') settings.min_order_qty = Number(minOrder);
        if (pack.trim() !== '') settings.pack_size = Number(pack);
        if (discontinued !== KEEP) settings.discontinued = discontinued === 'yes';
        if (Object.keys(settings).length === 0) {
            setError(t('nothing'));
            return;
        }

        setSaving(true);
        setError(null);
        try {
            const result = await api<{ updated: number }>('extension/product-settings', { method: 'POST', body: { product_ids: productIds, ...settings } });
            setUpdated(result.updated);
        } catch (e) {
            const code = e instanceof ApiError ? e.code : '';
            setError(t(code === 'network_error' ? 'errors.network' : code === 'validation_failed' ? 'errors.invalid' : 'errors.generic'));
        } finally {
            setSaving(false);
        }
    };

    if (updated !== null) {
        return (
            <s-admin-action heading={t('name')}>
                <s-banner tone={updated > 0 ? 'success' : 'warning'}>{updated > 0 ? t('done', { count: updated }) : t('noneKnown')}</s-banner>
                <s-button slot="primary-action" onClick={() => close()}>
                    {t('close')}
                </s-button>
            </s-admin-action>
        );
    }

    return (
        <s-admin-action heading={t('name')} loading={suppliers === null}>
            <s-stack gap="base">
                <s-paragraph>{t('intro', { count: productIds.length })}</s-paragraph>
                {error && <s-banner tone="critical">{error}</s-banner>}
                <s-select label={t('supplier')} value={supplier} onChange={(e) => setSupplier(e.currentTarget.value)} details={suppliers?.length === 0 ? t('noSuppliers') : undefined}>
                    <s-option value={KEEP}>{t('keep')}</s-option>
                    <s-option value={NONE}>{t('noSupplier')}</s-option>
                    {suppliers?.map((s) => (
                        <s-option key={s.id} value={String(s.id)}>
                            {s.name}
                        </s-option>
                    ))}
                </s-select>
                <s-number-field label={t('leadTime')} details={t('leadTimeHelp')} min={0} max={365} step={1} value={leadTime} placeholder={t('keep')} onInput={(e) => setLeadTime(e.currentTarget.value)} />
                <s-number-field label={t('safety')} details={t('safetyHelp')} min={0} max={365} step={1} value={safety} placeholder={t('keep')} onInput={(e) => setSafety(e.currentTarget.value)} />
                <s-number-field label={t('minOrder')} details={t('minOrderHelp')} min={1} step={1} value={minOrder} placeholder={t('keep')} onInput={(e) => setMinOrder(e.currentTarget.value)} />
                <s-number-field label={t('packSize')} details={t('packSizeHelp')} min={1} step={1} value={pack} placeholder={t('keep')} onInput={(e) => setPack(e.currentTarget.value)} />
                <s-select label={t('discontinued')} details={t('discontinuedHelp')} value={discontinued} onChange={(e) => setDiscontinued(e.currentTarget.value)}>
                    <s-option value={KEEP}>{t('discontinuedKeep')}</s-option>
                    <s-option value="yes">{t('discontinuedYes')}</s-option>
                    <s-option value="no">{t('discontinuedNo')}</s-option>
                </s-select>
            </s-stack>
            <s-button slot="primary-action" variant="primary" onClick={apply} loading={saving || undefined}>
                {t('apply')}
            </s-button>
            <s-button slot="secondary-actions" onClick={() => close()}>
                {t('cancel')}
            </s-button>
        </s-admin-action>
    );
}
