import type { ReactNode } from 'react';
import type { SectionGroup } from '@/components/layout/SectionTabs';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { useDashboard } from '@/features/dashboard/hooks/useDashboard';
import type { Dashboard } from '@/features/dashboard/types';

/** Loading and error states shared by the pages built from the dashboard data (Home, Reorder, Insights). */
export function DashboardGate({ heading, group, children }: { heading: string; group?: SectionGroup; children: (data: Dashboard) => ReactNode }) {
    const { data, isPending, error, refetch } = useDashboard();

    if (isPending) return <LoadingPage heading={heading} group={group} />;
    if (error || !data) {
        return (
            <s-page heading={heading}>
                <ErrorBanner error={error} onRetry={() => refetch()} />
            </s-page>
        );
    }

    return <>{children(data)}</>;
}
