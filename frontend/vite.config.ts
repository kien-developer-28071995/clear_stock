import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig(({ mode }) => {
    // frontend/.env (see .env.example)
    const env = loadEnv(mode, process.cwd(), '');
    // In dev the page is served from the tunnel (https, inside the Shopify admin iframe).
    // nginx proxies Vite's paths + HMR websocket, so assets share that same origin.
    const appUrl = env.APP_URL ? new URL(env.APP_URL) : null;
    const viaTunnel = appUrl?.protocol === 'https:';

    return {
        plugins: [
            laravel({
                input: ['src/main.tsx'],
                // Laravel (backend/) serves the page: write the build + hot file into its public dir.
                publicDirectory: '../backend/public',
                hotFile: '../backend/public/hot',
                refresh: ['../backend/resources/views/**'],
            }),
            react(),
        ],
        resolve: {
            alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
        },
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            allowedHosts: true,
            origin: viaTunnel ? appUrl!.origin : undefined,
            hmr: viaTunnel
                ? { protocol: 'wss', host: appUrl!.hostname, clientPort: 443, path: '/__vite_hmr' }
                : undefined,
        },
    };
});
