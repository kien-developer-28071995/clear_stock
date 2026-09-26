/** Server-provided config for the public pages, injected by resources/views/public-app.blade.php. */
export interface PublicConfig {
    appName: string;
    supportEmail: string;
    /** Y-m-d of the last privacy policy change. */
    privacyUpdated: string;
}

declare global {
    interface Window {
        __PUBLIC_CONFIG__?: Partial<PublicConfig>;
    }
}

export const publicConfig: PublicConfig = {
    appName: window.__PUBLIC_CONFIG__?.appName ?? 'Clear Stock',
    supportEmail: window.__PUBLIC_CONFIG__?.supportEmail ?? '',
    privacyUpdated: window.__PUBLIC_CONFIG__?.privacyUpdated ?? '',
};
