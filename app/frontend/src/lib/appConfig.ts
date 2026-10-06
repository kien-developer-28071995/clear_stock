/** Build-time config, written into index.html (%VITE_*%). */
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
