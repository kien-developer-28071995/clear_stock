import { useTranslation } from 'react-i18next';
import { SectionTabs, type SectionGroup } from '@/components/layout/SectionTabs';

/** `group`: the page belongs to a group with section tabs, which stay in place while it loads. */
export function LoadingPage({ heading, group }: { heading: string; group?: SectionGroup }) {
    const { t } = useTranslation();

    return (
        <s-page heading={heading}>
            {group && <SectionTabs group={group} />}
            <s-section>
                <s-stack direction="inline" gap="small-200" alignItems="center">
                    <s-spinner accessibilityLabel={t('common.loading')} size="base" />
                    <s-text color="subdued">{t('common.loadingEllipsis')}</s-text>
                </s-stack>
            </s-section>
        </s-page>
    );
}
