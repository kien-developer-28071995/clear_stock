/**
 * Build-time settings (website/.env, see .env.example) and the facts the pages show.
 * Prices and limits mirror app/backend/config/billing.php: update both together.
 */
const env = import.meta.env;

export const site = {
    appName: env.APP_NAME || 'Clear Stock',
    supportEmail: env.SUPPORT_EMAIL || '',
    // The App Store listing. Empty until the app is listed: every Install button is then left out.
    installUrl: env.INSTALL_URL || null,
    growthOffered: env.GROWTH_OFFERED === 'true',
    // Crisp live chat (crisp.chat → Settings → Website ID). Empty = no chat widget, nothing loaded.
    crispWebsiteId: env.CRISP_WEBSITE_ID || null,
};

/** Y-m-d of the last privacy policy change: bump when legal.privacy changes in the locale files. */
export const PRIVACY_UPDATED = '2026-09-26';

export const pricing = {
    currency: 'USD',
    trialDays: 7,
    freeProducts: 50,
    starter: { monthly: 4, annual: 38 },
    growth: { monthly: 6, annual: 58 },
};
