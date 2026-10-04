import type { ReactNode } from 'react';
import { useSearchParams } from 'react-router';

interface Tab<T extends string> {
    id: T;
    label: string;
}

/**
 * The tab of a page, kept in the URL (?tab=) so a link, a reload or Back lands on the same one.
 * The first tab is the default and leaves the URL clean.
 */
export function useTab<T extends string>(ids: readonly T[]): [T, (tab: T) => void] {
    const [params, setParams] = useSearchParams();
    const current = ids.find((id) => id === params.get('tab')) ?? ids[0];
    const select = (tab: T) => {
        const next = new URLSearchParams(params);
        if (tab === ids[0]) next.delete('tab');
        else next.set('tab', tab);
        setParams(next, { replace: true });
    };

    return [current, select];
}

/** Tabs of a long page. Polaris web components have no tabs: a row of toggle buttons, one pressed. */
export function Tabs<T extends string>({ tabs, value, onChange, label }: { tabs: Tab<T>[]; value: T; onChange: (tab: T) => void; label: string }) {
    return (
        <div role="tablist" aria-label={label} style={{ paddingBlockEnd: 8 }}>
            <s-stack direction="inline" gap="small-200">
                {tabs.map((tab) => (
                    <s-press-button key={tab.id} variant="tertiary" pressed={tab.id === value || undefined} onClick={() => onChange(tab.id)}>
                        {tab.label}
                    </s-press-button>
                ))}
            </s-stack>
        </div>
    );
}

/**
 * The content of one tab. Hidden rather than unmounted when another tab is open, so a
 * half-filled form (and its save bar) survives switching tabs.
 */
export function TabPanel({ active, children }: { active: boolean; children: ReactNode }) {
    return (
        <s-stack gap="base" display={active ? 'auto' : 'none'}>
            {children}
        </s-stack>
    );
}
