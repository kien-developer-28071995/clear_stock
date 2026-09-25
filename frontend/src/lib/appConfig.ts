/** Server-provided config, injected by resources/views/app.blade.php. */
export interface AppConfig {
    appName: string;
}

declare global {
    interface Window {
        __APP_CONFIG__?: Partial<AppConfig>;
    }
}

export const appConfig: AppConfig = {
    appName: window.__APP_CONFIG__?.appName ?? 'Clear Stock',
};
