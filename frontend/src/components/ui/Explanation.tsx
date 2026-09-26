import { useTranslation } from 'react-i18next';
import { translateCode } from '@/i18n/codes';
import type { Coded } from '@/types/coded';

/** "Why this number" sentences, from the forecast's explanation lines ({code, params}). */
export function Explanation({ lines, limit }: { lines: Coded[]; limit?: number }) {
    useTranslation(); // re-render when the language changes
    const shown = limit ? lines.slice(0, limit) : lines;
    return (
        <s-unordered-list>
            {shown.map((line, i) => (
                <s-list-item key={`${line.code}-${i}`}>{translateCode('explanation', line)}</s-list-item>
            ))}
        </s-unordered-list>
    );
}
