/**
 * Text from the API arrives as a snake_case code plus raw params, never as a
 * sentence; the app translates it (see `@/i18n/codes`).
 */
export interface Coded {
    code: string;
    params: Record<string, unknown>;
}
