import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router';
import { Tip } from '@/features/setup/components/Tip';
import { useRecordSetupEvent, useSetupGuide } from '@/features/setup/hooks/useSetupGuide';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { useForecast } from '@/features/forecasts/hooks/useForecasts';
import { ForecastSummary } from '@/features/forecasts/components/ForecastSummary';
import { WhyThisNumber } from '@/features/forecasts/components/WhyThisNumber';
import { AdjustForecastForm } from '@/features/forecasts/components/AdjustForecastForm';
import { ProductSettingsForm } from '@/features/forecasts/components/ProductSettingsForm';
import { AlternateSuppliers } from '@/features/forecasts/components/AlternateSuppliers';
import { LocationForecasts } from '@/features/forecasts/components/LocationForecasts';
import { MarkOrderedModal } from '@/features/orders/components/MarkOrderedModal';
import { useModal } from '@/hooks/useModal';
import { TabPanel, Tabs, useTab } from '@/components/ui/Tabs';

const PRODUCT_TABS = ['forecast', 'settings', 'suppliers'] as const;

export function ProductDetailPage() {
    const { t } = useTranslation();
    const variantId = Number(useParams().variantId);
    const { data: f, isPending, error, refetch } = useForecast(variantId);
    const guide = useSetupGuide();
    const record = useRecordSetupEvent();
    const markModal = useModal();
    const [tab, setTab] = useTab(PRODUCT_TABS);

    // Setup guide step "See why a product needs reordering" completes on the first visit.
    const reviewed = guide.data?.steps.find((s) => s.key === 'review_forecast')?.done;
    useEffect(() => {
        if (f && reviewed === false && !record.isPending) record.mutate('viewed_forecast');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [f, reviewed]);

    if (isPending) return <LoadingPage heading={t('product.heading')} />;

    if (error || !f) {
        return (
            <s-page heading={t('product.heading')}>
                <s-link slot="breadcrumb-actions" href="/products">{t('nav.products')}</s-link>
                <ErrorBanner error={error} onRetry={() => refetch()} />
            </s-page>
        );
    }

    return (
        <s-page heading={f.name}>
            <s-link slot="breadcrumb-actions" href="/products">{t('nav.products')}</s-link>
            {f.status !== 'discontinued' && (
                <s-button slot="secondary-actions" onClick={() => markModal.open()}>
                    {t('orders.mark')}
                </s-button>
            )}
            <MarkOrderedModal
                id="mark-ordered-product"
                modalRef={markModal.ref}
                items={[{ variant_id: f.variant_id, name: f.name, quantity: Math.max(1, f.suggested_qty) }]}
            />
            <ForecastSummary f={f} />
            <Tabs
                label={t('product.heading')}
                value={tab}
                onChange={setTab}
                tabs={[
                    { id: 'forecast', label: t('tabs.product.forecast') },
                    { id: 'settings', label: t('tabs.product.settings') },
                    { id: 'suppliers', label: t('tabs.product.suppliers') },
                ]}
            />
            <TabPanel active={tab === 'forecast'}>
                <Tip id="product_explanation">{t('tips.product_explanation')}</Tip>
                <WhyThisNumber f={f} />
                <AdjustForecastForm f={f} />
                <LocationForecasts f={f} />
            </TabPanel>
            <TabPanel active={tab === 'settings'}>
                <ProductSettingsForm f={f} />
            </TabPanel>
            <TabPanel active={tab === 'suppliers'}>
                <AlternateSuppliers f={f} />
            </TabPanel>
        </s-page>
    );
}
