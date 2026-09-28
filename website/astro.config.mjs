// @ts-check
import { defineConfig } from 'astro/config';
import { loadEnv } from 'vite';

const { SITE_URL } = loadEnv(process.env.NODE_ENV ?? 'production', process.cwd(), '');

// Static marketing site: every page is plain HTML (privacy.html, vi/privacy.html...), served
// by Caddy in production (deploy/Caddyfile) or any static host.
export default defineConfig({
    site: SITE_URL || 'http://localhost:4321',
    output: 'static',
    build: { format: 'file' },
    trailingSlash: 'never',
    server: { port: 4321 },
    devToolbar: { enabled: false },
});
