import { useState, type ReactNode } from 'react';
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
        // Room below: the first card or banner of the tab must not touch the buttons.
        <s-box paddingBlockEnd="base">
            {/* One line, always: on a narrow screen the row scrolls sideways instead of wrapping. */}
            <div role="tablist" aria-label={label} style={{ display: 'flex', gap: 8, flexWrap: 'nowrap', overflowX: 'auto', whiteSpace: 'nowrap' }}>
                {tabs.map((tab) => (
                    <div key={tab.id} style={{ flex: 'none' }}>
                        <s-press-button variant="tertiary" pressed={tab.id === value || undefined} onClick={() => onChange(tab.id)}>
                            {tab.label}
                        </s-press-button>
                    </div>
                ))}
            </div>
        </s-box>
    );
}

/**
 * The content of one tab. Mounted the first time its tab is opened (so a hidden tab loads no
 * data), then kept and only hidden: a half-filled form and its save bar survive switching tabs.
 */
export function TabPanel({ active, children }: { active: boolean; children: ReactNode }) {
    const [opened, setOpened] = useState(active);
    if (active && !opened) setOpened(true);
    if (!opened) return null;

    return (
        <s-stack gap="base" display={active ? 'auto' : 'none'}>
            {children}
        </s-stack>
    );
}
