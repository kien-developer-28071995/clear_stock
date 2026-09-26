import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useSearchParams } from 'react-router';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { ExportPurchaseOrderButton } from '@/features/forecasts/components/ExportPurchaseOrderButton';
import { useShop } from '@/features/shop/hooks/useShop';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useForecastList, useLocations } from '@/features/forecasts/hooks/useForecasts';
import type { ForecastFilters } from '@/features/forecasts/types';
import { formatDate, formatNumber } from '@/utils/format';

const STATUS_OPTIONS = ['reorder_now', 'out_of_stock', 'slow', 'healthy'] as const;
const SORT_OPTIONS = ['urgency', 'cover', 'suggested', 'value', 'name'] as const;

/** Every tracked product with its forecast. Filters live in the URL so dashboard cards can link here. */
export function ProductsPage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const [params, setParams] = useSearchParams();
    const filters: ForecastFilters = {
        location_id: params.get('location_id') ? Number(params.get('location_id')) : '',
        status: (params.get('status') ?? '') as ForecastFilters['status'],
        search: params.get('search') ?? '',
        sort: (params.get('sort') ?? 'urgency') as ForecastFilters['sort'],
        page: Number(params.get('page') ?? 1),
    };
    const [search, setSearch] = useState(filters.search ?? '');
    const { data, isPending, isFetching, error, refetch } = useForecastList(filters);
    const entitlements = useShop().data?.entitlements;
    const limit = entitlements?.max_skus ?? null;
    const locations = useLocations(!!entitlements?.locations);
    const showLocations = (locations.data?.length ?? 0) > 1;

    const update = (patch: Partial<ForecastFilters>) => {
        const next = { ...filters, page: 1, ...patch };
        setParams(Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v !== undefined).map(([k, v]) => [k, String(v)])));
    };

    // Debounce typing in the search box.
    useEffect(() => {
        if (search === (filters.search ?? '')) return;
        const timer = setTimeout(() => update({ search }), 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const meta = data?.meta;

    return (
        <s-page heading={t('nav.products')} inlineSize="large">
            <s-stack slot="secondary-actions">
                <ExportPurchaseOrderButton locationId={filters.location_id || undefined} />
            </s-stack>
            {error && <ErrorBanner error={error} onRetry={() => refetch()} />}
            {/* Free plan: the list is capped at the plan's best sellers. */}
            {limit !== null && (meta?.total ?? 0) >= limit && (
                <s-banner tone="info">
                    {t('products.freeLimit', { count: limit })} <s-link href="/plans">{t('upgrade.upgrade')}</s-link>
                </s-banner>
            )}
            <s-section padding="none">
                <s-table
                    loading={isPending || isFetching || undefined}
                    paginate
                    hasPreviousPage={(meta?.current_page ?? 1) > 1}
                    hasNextPage={(meta?.current_page ?? 1) < (meta?.last_page ?? 1)}
                    onPreviousPage={() => update({ page: (filters.page ?? 1) - 1 })}
                    onNextPage={() => update({ page: (filters.page ?? 1) + 1 })}
                >
                    <s-grid slot="filters" gap="small-200" gridTemplateColumns={
                            // Narrow screens: one filter per line.
                            showLocations ? '@container (inline-size > 560px) 1fr auto auto auto, 1fr' : '@container (inline-size > 560px) 1fr auto auto, 1fr'
                        }>
                        <s-search-field
                            label={t('products.search')}
                            labelAccessibilityVisibility="exclusive"
                            placeholder={t('products.searchPlaceholder')}
                            value={search}
                            onInput={(e) => setSearch(e.currentTarget.value)}
                        />
                        {showLocations && (
                            <s-select
                                label={t('locations.location')}
                                labelAccessibilityVisibility="exclusive"
                                value={String(filters.location_id ?? '')}
                                onChange={(e) => update({ location_id: e.currentTarget.value ? Number(e.currentTarget.value) : '' })}
                            >
                                <s-option value="">{t('products.allLocations')}</s-option>
                                {locations.data?.map((l) => (
                                    <s-option key={l.id} value={String(l.id)}>{l.name}</s-option>
                                ))}
                            </s-select>
                        )}
                        <s-select
                            label={t('products.status')}
                            labelAccessibilityVisibility="exclusive"
                            value={filters.status ?? ''}
                            onChange={(e) => update({ status: e.currentTarget.value as ForecastFilters['status'] })}
                        >
                            <s-option value="">{t('products.allProducts')}</s-option>
                            {STATUS_OPTIONS.map((value) => (
                                <s-option key={value} value={value}>{t(`status.${value}`)}</s-option>
                            ))}
                        </s-select>
                        <s-select
                            label={t('products.sort')}
                            labelAccessibilityVisibility="exclusive"
                            value={filters.sort ?? 'urgency'}
                            onChange={(e) => update({ sort: e.currentTarget.value as ForecastFilters['sort'] })}
                        >
                            {SORT_OPTIONS.map((value) => (
                                <s-option key={value} value={value}>{t(`products.sorts.${value}`)}</s-option>
                            ))}
                        </s-select>
                    </s-grid>

                    <s-table-header-row>
                        <s-table-header listSlot="primary">{t('table.product')}</s-table-header>
                        <s-table-header listSlot="inline">{t('products.status')}</s-table-header>
                        <s-table-header format="numeric">{t('table.inStock')}</s-table-header>
                        <s-table-header format="numeric">{t('table.perDay')}</s-table-header>
                        <s-table-header format="numeric">{t('table.daysLeft')}</s-table-header>
                        <s-table-header>{t('table.orderBy')}</s-table-header>
                        <s-table-header format="numeric">{t('table.suggestedOrder')}</s-table-header>
                    </s-table-header-row>
                    <s-table-body>
                        {data?.data.map((row) => (
                            <s-table-row key={row.variant_id} clickDelegate={`product-${row.variant_id}`}>
                                <s-table-cell>
                                    <s-stack gap="small-100">
                                        <s-link id={`product-${row.variant_id}`} onClick={() => navigate(`/products/${row.variant_id}`)}>
                                            {row.name}
                                        </s-link>
                                        {row.sku && <s-text color="subdued">{row.sku}</s-text>}
                                    </s-stack>
                                </s-table-cell>
                                <s-table-cell>
                                    <StatusBadge status={row.status} />
                                </s-table-cell>
                                <s-table-cell>
                                    <s-stack gap="small-100">
                                        <s-text>{formatNumber(row.current_stock, 0)}</s-text>
                                        {row.incoming_stock > 0 && (
                                            <s-text color="subdued">{t('product.incomingShort', { qty: formatNumber(row.incoming_stock, 0) })}</s-text>
                                        )}
                                    </s-stack>
                                </s-table-cell>
                                <s-table-cell>{formatNumber(row.avg_daily_sales, 2)}</s-table-cell>
                                <s-table-cell>{row.days_of_cover === null ? '∞' : formatNumber(row.days_of_cover, 0)}</s-table-cell>
                                <s-table-cell>{row.avg_daily_sales > 0 ? formatDate(row.reorder_date) : '—'}</s-table-cell>
                                <s-table-cell>{formatNumber(row.suggested_qty, 0)}</s-table-cell>
                            </s-table-row>
                        ))}
                    </s-table-body>
                </s-table>
                {data && data.data.length === 0 && (
                    <s-box padding="base">
                        <s-text color="subdued">{t('products.noMatch')}</s-text>
                    </s-box>
                )}
            </s-section>
            {meta && (
                <s-text color="subdued">
                    {t('products.pageInfo', { count: meta.total, total: formatNumber(meta.total), page: meta.current_page, pages: meta.last_page })}
                </s-text>
            )}
        </s-page>
    );
}
