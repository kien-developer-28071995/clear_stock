import { render } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { api, ApiError } from '../../shared/api';
import { formatDate, formatNumber } from '../../shared/i18n';

interface Variant {
    variant_id: number;
    title: string | null;
    sku: string | null;
    discontinued: boolean;
    ordered: { units: number; expected_on: string } | null;
    forecast: { current_stock: number; suggested_qty: number; reorder_date: string | null } | null;
}

type State = { kind: 'loading' } | { kind: 'error'; message: string } | { kind: 'ready'; synced: boolean; variants: Variant[] };

export default async () => {
    render(<ProductOrderAction />, document.body);
};

/**
 * Product (or variant) page › More actions › Mark as ordered: for an order placed outside Shopify.
 * Quantities start at the suggested order; the app then counts them as on the way.
 */
function ProductOrderAction() {
    const { i18n, data, close } = shopify;
    const t = i18n.translate;
    const gid = data.selected[0]?.id ?? '';
    const path = gid.includes('/ProductVariant/') ? `extension/variants/${gid.split('/').pop()}` : `extension/products/${gid.split('/').pop()}`;

    const [state, setState] = useState<State>({ kind: 'loading' });
    const [qty, setQty] = useState<Record<number, string>>({});
    const [expected, setExpected] = useState('');
    const [reference, setReference] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [recorded, setRecorded] = useState<number | null>(null);

    useEffect(() => {
        api<{ synced: boolean; variants: Variant[] }>(path)
            .then((result) => {
                const variants = result.variants.filter((v) => v.forecast && !v.discontinued);
                setQty(Object.fromEntries(variants.map((v) => [v.variant_id, v.forecast!.suggested_qty > 0 ? String(v.forecast!.suggested_qty) : ''])));
                setState({ kind: 'ready', synced: result.synced, variants });
            })
            .catch((e) => setState({ kind: 'error', message: t(e instanceof ApiError && e.code === 'network_error' ? 'errors.network' : 'errors.generic') }));
    }, [path]);

    const items = Object.entries(qty)
        .map(([id, value]) => ({ variant_id: Number(id), quantity: Number(value) }))
        .filter((i) => Number.isInteger(i.quantity) && i.quantity > 0);

    const save = async () => {
        if (items.length === 0) {
            setError(t('nothing'));
            return;
        }
        setSaving(true);
        setError(null);
        try {
            const result = await api<{ recorded: number }>('extension/manual-orders', {
                method: 'POST',
                body: { items, expected_on: expected || null, reference: reference.trim() || null },
            });
            setRecorded(result.recorded);
        } catch (e) {
            const code = e instanceof ApiError ? e.code : '';
            setError(t(code === 'network_error' ? 'errors.network' : code === 'validation_failed' ? 'errors.invalid' : 'errors.generic'));
        } finally {
            setSaving(false);
        }
    };

    if (recorded !== null) {
        return (
            <s-admin-action heading={t('name')}>
                <s-stack gap="base">
                    <s-banner tone="success">{t('done', { count: recorded })}</s-banner>
                    <s-link href="app:orders">{t('openOrders')}</s-link>
                </s-stack>
                <s-button slot="primary-action" onClick={() => close()}>
                    {t('close')}
                </s-button>
            </s-admin-action>
        );
    }

    if (state.kind !== 'ready') {
        return (
            <s-admin-action heading={t('name')} loading={state.kind === 'loading'}>
                {state.kind === 'error' && <s-banner tone="critical">{state.message}</s-banner>}
                <s-button slot="secondary-actions" onClick={() => close()}>
                    {t('cancel')}
                </s-button>
            </s-admin-action>
        );
    }

    const showVariant = state.variants.some((v) => v.title);

    return (
        <s-admin-action heading={t('name')}>
            <s-stack gap="base">
                {!state.synced || state.variants.length === 0 ? (
                    <s-paragraph>{t(state.synced ? 'noForecast' : 'notSynced')}</s-paragraph>
                ) : (
                    <>
                        <s-paragraph>{t('intro')}</s-paragraph>
                        {error && <s-banner tone="critical">{error}</s-banner>}
                        {state.variants.map((v) => {
                            const f = v.forecast!;
                            const hint = [
                                t('inStock', { qty: formatNumber(i18n, f.current_stock, 0) }),
                                f.suggested_qty > 0 ? t('suggested', { qty: formatNumber(i18n, f.suggested_qty, 0), date: formatDate(i18n, f.reorder_date) }) : t('nothingSuggested'),
                                v.ordered ? t('alreadyOrdered', { qty: formatNumber(i18n, v.ordered.units, 0), date: formatDate(i18n, v.ordered.expected_on) }) : null,
                            ].filter(Boolean).join(' · ');
                            return (
                                <s-number-field
                                    key={v.variant_id}
                                    label={showVariant ? v.title ?? v.sku ?? t('quantity') : t('quantity')}
                                    details={hint}
                                    min={0}
                                    step={1}
                                    value={qty[v.variant_id] ?? ''}
                                    onInput={(e) => {
                                        const value = e.currentTarget.value;
                                        setQty((cur) => ({ ...cur, [v.variant_id]: value }));
                                    }}
                                />
                            );
                        })}
                        <s-date-field
                            label={t('expected')}
                            details={t('expectedHelp')}
                            value={expected}
                            onInput={(e) => setExpected(e.currentTarget.value)}
                            onChange={(e) => setExpected(e.currentTarget.value)}
                        />
                        <s-text-field label={t('reference')} placeholder="PO-1024" value={reference} onInput={(e) => setReference(e.currentTarget.value)} />
                        <s-text color="subdued">{t('help')}</s-text>
                    </>
                )}
            </s-stack>
            {state.synced && state.variants.length > 0 && (
                <s-button slot="primary-action" variant="primary" onClick={save} loading={saving || undefined}>
                    {t('save')}
                </s-button>
            )}
            <s-button slot="secondary-actions" onClick={() => close()}>
                {t('cancel')}
            </s-button>
        </s-admin-action>
    );
}
