import type { APIRoute } from 'astro';
import { LOCALES, localePath } from '../i18n';

const PAGES = ['', 'support', 'privacy'];

/** Every page in every language, with its language alternates. */
export const GET: APIRoute = ({ site }) => {
    const url = (path: string) => new URL(path, site).href;
    const entries = PAGES.flatMap((page) =>
        LOCALES.map((locale) => {
            const alternates = LOCALES.map((l) => `<xhtml:link rel="alternate" hreflang="${l}" href="${url(localePath(l, page))}"/>`).join('');
            return `<url><loc>${url(localePath(locale, page))}</loc>${alternates}</url>`;
        }),
    );
    return new Response(
        `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">${entries.join('')}</urlset>\n`,
        { headers: { 'Content-Type': 'application/xml' } },
    );
};
