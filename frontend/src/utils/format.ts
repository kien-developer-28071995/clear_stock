import { currentLocale } from '@/i18n';

/**
 * Formatting helpers. Dates, numbers and money follow the app's current locale
 * (the Shopify admin language), never hard-coded conventions.
 */

/** "5 minutes ago", "yesterday"… */
export function timeAgo(iso: string, now: Date = new Date()): string {
    const seconds = Math.round((new Date(iso).getTime() - now.getTime()) / 1000);
    const rtf = new Intl.RelativeTimeFormat(currentLocale(), { numeric: 'auto' });
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];
    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) return rtf.format(Math.round(seconds / size), unit);
    }
    return rtf.format(0, 'minute');
}

export function formatNumber(value: number, maxFractionDigits = 1): string {
    return new Intl.NumberFormat(currentLocale(), { maximumFractionDigits: maxFractionDigits }).format(value);
}

export function formatMoney(value: number, currency: string | null): string {
    if (!currency) return formatNumber(value, 2);
    return new Intl.NumberFormat(currentLocale(), { style: 'currency', currency, maximumFractionDigits: 0 }).format(value);
}

/** Short day + month for a Y-m-d date string (no timezone shift): "Oct 15" / "15 thg 10". */
export function formatDate(ymd: string | null): string {
    if (!ymd) return '—';
    const [y, m, d] = ymd.split('-').map(Number);
    return new Intl.DateTimeFormat(currentLocale(), { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(
        new Date(Date.UTC(y, m - 1, d)),
    );
}

/** Days between today (Y-m-d, shop time) and a date; negative = past. */
export function daysUntil(ymd: string | null, today: string): number | null {
    if (!ymd) return null;
    return Math.round((Date.parse(ymd) - Date.parse(today)) / 86400000);
}

/** ISO weekday (1 = Monday ... 7 = Sunday) as a short name in the app language: "Mon" / "Th 2". */
export function weekdayName(isoDay: number, width: 'short' | 'long' = 'short'): string {
    // 2024-01-01 was a Monday.
    return new Intl.DateTimeFormat(currentLocale(), { weekday: width, timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, isoDay)));
}

/** Weekday + short date: "Mon, Oct 5". */
export function formatDateWithWeekday(ymd: string | null): string {
    if (!ymd) return '—';
    const [y, m, d] = ymd.split('-').map(Number);
    return new Intl.DateTimeFormat(currentLocale(), { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' }).format(
        new Date(Date.UTC(y, m - 1, d)),
    );
}

/** "2026-10" -> "October 2026" / "tháng 10 năm 2026". */
export function formatMonth(ym: string): string {
    return new Intl.DateTimeFormat(currentLocale(), { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${ym}-01T00:00:00Z`));
}
