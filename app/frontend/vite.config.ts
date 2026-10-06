import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

/**
 * The embedded app is a static site of its own (index.html + assets in dist/): it has its own
 * domain and talks to the backend only through the API at VITE_API_URL. Laravel serves none of it.
 */
export default defineConfig(({ mode, command }) => {
    // app/frontend/.env (see .env.example)
    const env = loadEnv(mode, process.cwd(), '');
    if (command === 'build') {
        // A build without these would ship an app that cannot reach its API or start App Bridge.
        for (const key of ['VITE_API_URL', 'VITE_SHOPIFY_API_KEY']) {
            if (!env[key]) throw new Error(`${key} must be set to build the frontend (app/frontend/.env.example)`);
        }
    }
    // Used in index.html (%VITE_APP_NAME%).
    process.env.VITE_APP_NAME = env.VITE_APP_NAME || 'Clear Stock';

    // In dev the page is opened through the tunnel (https, inside the Shopify admin iframe). The
    // dev proxy (docker/nginx/dev.conf) sends the frontend's paths + HMR websocket here.
    const appUrl = env.APP_URL ? new URL(env.APP_URL) : null;
    const viaTunnel = appUrl?.protocol === 'https:';

    return {
        plugins: [react()],
        resolve: {
            alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
        },
        build: { outDir: 'dist', emptyOutDir: true },
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            allowedHosts: true,
            // Playwright writes traces and reports here while the app is open: not source, no reload.
            watch: { ignored: ['**/e2e-results/**', '**/e2e-report/**', '**/dist/**'] },
            origin: viaTunnel ? appUrl!.origin : undefined,
            hmr: viaTunnel
                ? { protocol: 'wss', host: appUrl!.hostname, clientPort: 443, path: '/__vite_hmr' }
                : undefined,
        },
    };
});
