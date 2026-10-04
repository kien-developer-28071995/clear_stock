import { useTranslation } from 'react-i18next';
import { DashboardGate } from '@/features/dashboard/components/DashboardGate';
import { RunwayChart } from '@/features/dashboard/components/RunwayChart';
import { Overstock } from '@/features/dashboard/components/Overstock';
import { SlowMovers } from '@/features/dashboard/components/SlowMovers';
import { LostSales } from '@/features/dashboard/components/LostSales';
import { AbcSummary } from '@/features/dashboard/components/AbcSummary';
import { ForecastAccuracy } from '@/features/dashboard/components/ForecastAccuracy';
import { DiscontinuedStock } from '@/features/dashboard/components/DiscontinuedStock';
import { ClearanceList } from '@/features/reports/components/ClearanceList';
import { SizeRuns } from '@/features/reports/components/SizeRuns';
import { StockHistoryChart } from '@/features/reports/components/StockHistoryChart';
import { TabPanel, Tabs, useTab } from '@/components/ui/Tabs';
import { Tip } from '@/features/setup/components/Tip';

const INSIGHT_TABS = ['stock', 'excess', 'products'] as const;

/** The overview: days of stock left per product, and money tied up in slow stock. */
export function InsightsPage() {
    const { t } = useTranslation();
    const [tab, setTab] = useTab(INSIGHT_TABS);

    return (
        <DashboardGate heading={t('nav.insights')}>
            {(data) => (
                <s-page heading={t('nav.insights')}>
                    <s-link slot="breadcrumb-actions" href="/">{t('nav.home')}</s-link>
                    <Tabs
                        label={t('nav.insights')}
                        value={tab}
                        onChange={setTab}
                        tabs={[
                            { id: 'stock', label: t('tabs.insights.stock') },
                            { id: 'excess', label: t('tabs.insights.excess') },
                            { id: 'products', label: t('tabs.insights.products') },
                        ]}
                    />
                    <TabPanel active={tab === 'stock'}>
                        <Tip id="home_runway">{t('tips.home_runway')}</Tip>
                        <RunwayChart items={data.runway} />
                        <LostSales dashboard={data} />
                        <StockHistoryChart />
                    </TabPanel>
                    <TabPanel active={tab === 'excess'}>
                        <Overstock dashboard={data} />
                        <SlowMovers dashboard={data} />
                        <ClearanceList />
                        <DiscontinuedStock dashboard={data} />
                        {data.slow_movers.count === 0 && data.overstock.count === 0 && (
                            <s-section>
                                <s-paragraph>{t('tabs.insights.excessEmpty')}</s-paragraph>
                            </s-section>
                        )}
                    </TabPanel>
                    <TabPanel active={tab === 'products'}>
                        <AbcSummary dashboard={data} />
                        <SizeRuns />
                        <ForecastAccuracy />
                    </TabPanel>
                    {tab === 'stock' && data.runway.length === 0 && (
                        <s-section>
                            <s-paragraph>{t('insights.empty')}</s-paragraph>
                        </s-section>
                    )}
                </s-page>
            )}
        </DashboardGate>
    );
}
