# Phase 7: App Store submission

Status 2026-09-26. `[x]` done in the repo, `[ ]` needs you (Partner Dashboard, hosting, recording).

## v1 feature set (decided 2026-09-26)

Goal: a first version that is quick to review (few scopes, no extra extensions, no emails to third
parties) but still clearly better than the competition. Everything else is built and tested; it is
switched off with `FEATURE_*` (README "Feature switches") and can be released later, one by one.

| Feature | v1 | Why |
|---|---|---|
| Forecasts, "why this number?", adjustments, bundles, alerts (digest), suppliers, Stocky import, vendors → suppliers, MOQ/pack, min/max, discontinued products, orders placed outside Shopify, incoming stock, sync-failure email, product-page block + bulk action | **On** (core, no switch) | The product. Read-only, no extra scope. |
| ABC classes | **On** | Read-only view of existing data. |
| Purchase order CSV export | **On** (Starter) | A download, nothing sent anywhere. |
| Sales what-if | **On** (Starter) | Read-only calculation. |
| Purchase plan (12 weeks of orders and spend, CSV export) | **On** (Starter) | Read-only calculation and a download. |
| Monthly order budget and priorities | **On** (Starter) | A setting and a ranked list. |
| Sales events (promotions, Black Friday) | **On** (every plan) | A setting that feeds the forecast. |
| Forecast accuracy report | **On** (every plan) | Read-only view: past forecasts next to what really sold. |
| One-off sales spikes ignored, sales lost to stock-outs | **On** (every plan) | Forecast quality and a read-only view; merchants can turn spike filtering off in Settings. |
| New products from a similar product | **On** (Starter) | A setting on the product page. |
| Emailing orders to suppliers | Off | Sends email to third parties on the merchant's behalf: more review questions and needs a verified sending domain first. |
| Per-location forecasts | Off | Needs 2 extra protected scopes (`read_*_fulfillment_orders`). |
| Transfers | Off | Needs locations and the optional `write_inventory_transfers` scope (the app's only write). |
| Real-time alerts | Off | Per-shop inventory webhooks: more moving parts for review. |
| Shopify Flow triggers | Off | 4 more extensions; the reviewer would have to install Flow. |

With every Growth-only feature off, **v1 sells Free and Starter only** (`BILLING_GROWTH_OFFERED=false`).
Growth comes back with the first of those features (turn it on, re-add its scope / extension, deploy).

Files: `backend/.env.production.example` (all of the above as env), `shopify.app.production.toml`
(v1 scopes, only the two product-page extensions).

## Checklist

### Done in the repo
- [x] Production app config without `write_orders`, fulfillment scopes or optional scopes (`shopify.app.production.toml`). The dev app keeps them (`dev:fake-orders`).
- [x] `SHOPIFY_BILLING_TEST=false` in the production env template; **development stores (the reviewer's) get test charges automatically** (`shop.plan.partnerDevelopment`), real stores are charged for real.
- [x] `php artisan app:preflight`: fails on debug on, test billing, `write_orders`, tunnel URL, log mailer, example emails, impossible feature combinations.
- [x] Privacy policy finalized (`/privacy`), support page with FAQ (`/support`): public React pages (`frontend/src/public.tsx`, `features/legal`), English, Vietnamese, Spanish, German, French and Portuguese (`?lang=vi`, `es`, `de`, `fr`, `pt`), no App Bridge. Text in `frontend/src/i18n/locales/*.json` under `legal`; bump `PublicPageController::PRIVACY_UPDATED` when the policy changes.
- [x] Mandatory compliance webhooks, HMAC, session tokens, uninstall cleanup (since Phase 2; tests).
- [x] Dev-only commands refuse to run outside `APP_ENV=local`.
- [x] Reviewer instructions and listing draft (below), sample CSV `docs/sample-purchase-orders.csv`.

### Needs you
- [ ] **Create the production app** in the Partner Dashboard, put its client id in `shopify.app.production.toml`, key/secret in the server `backend/.env`.
- [ ] **Host production** (HTTPS domain): `backend/.env` from `backend/.env.production.example`, `make prod-build prod-up`, `make prod-migrate`, then `php artisan app:preflight` must pass.
- [ ] **Email sending domain:** SPF + DKIM (+ DMARC) for the `MAIL_FROM_ADDRESS` domain at your email provider, a real `SUPPORT_EMAIL`.
- [ ] **Deploy the app config:** `npx @shopify/cli@latest app deploy --config production`.
- [ ] **Request `read_all_orders`** (Partner Dashboard → API access): text below.
- [ ] **Protected customer data:** Partner Dashboard → API access → Protected customer data: request access to orders at **Level 1** (no customer fields: name, email, phone, address are not needed). Answers below.
- [x] **Screenshots** 1600×900 of the v1 app (Starter, v1 switches, English): `docs/listing/screenshots/` (7). Regenerate after UI changes: `make listing-screenshots` (restores `.env` and the plan). The dev store's currency is shown as USD; its sample products have no unit cost, so cost figures show "—" (add costs in Shopify for richer shots).
- [x] **Draft walkthrough video** (WebM, 1280×720, 75 s, captions): `docs/listing/walkthrough.webm`, `make listing-video`. It does not show installing the app in the Shopify admin.
- [ ] **Listing:** your app icon, pick 3–6 of the screenshots, texts below, pricing (Free, Starter $4/month or $38/year, 7-day trial).
- [ ] **Screencast** (required): record it yourself in the real Shopify admin, since the review needs the install and permission screens: install → onboarding → sync → Home → product explanation → Plans (upgrade with the test charge). 2–4 minutes, English narration or captions. Follow the draft video's order and captions; upload to YouTube/Vimeo (unlisted) and paste the link.
- [ ] Install the production app on a fresh development store and run the reviewer steps below once yourself.
- [ ] Submit.

## `read_all_orders` request (paste)

> Clear Stock forecasts when each product will run out and how much to reorder. The forecast uses
> sales rates over the last 7, 30 and 90 days and compares the same period of the previous year to
> adjust for seasonality, which needs up to 400 days of orders. We only read line-item quantities,
> refunds and order dates, aggregate them into daily units sold per variant, and do not store orders
> or any customer data.

## Protected customer data (answers)

- Data used: orders (line items quantities and dates only). No customer name, email, phone or address.
- Purpose: inventory forecasting (app functionality).
- Stored: aggregated daily units per variant only; raw orders are not stored.
- Retention: deleted 48 hours after uninstall (shop/redact); `customers/redact` and `customers/data_request` have no customer data to act on.
- Encryption: HTTPS in transit; access tokens encrypted at rest.

## Testing instructions for the reviewer (paste)

> Clear Stock is an embedded app; no account or login is needed.
>
> 1. Install the app. You land in the app and see a 2-question setup (default lead time, alert email). Save.
> 2. The app imports orders and inventory in the background (progress bar on Home). This takes under a minute for a development store.
> 3. **Home** shows products to reorder and money tied up in slow stock. If your store has no orders, create 2–3 orders for a product with inventory in the Shopify admin, then open **Settings → Store data → Sync now**; forecasts appear after the sync.
> 4. **Products → any product**: the forecast and "Why these numbers?" explain every figure (sales windows, out-of-stock days left out, lead time, safety stock). Try "Adjust the sales rate" and see the reorder suggestion change.
> 5. **Suppliers → Import from Stocky**: upload the attached `sample-purchase-orders.csv` to see suppliers and lead times created from purchase orders (products match by SKU, so sample SKUs may not match your products).
> 6. **Plans**: choose Starter and approve the charge (a test charge on development stores). Starter unlocks unlimited products, bundles, email alerts, purchase order export and the sales what-if (**What-if** in the menu).
> 7. On a Shopify product page, the **Stock forecast** block (add it via the page's block menu if it isn't shown) shows the forecast per variant; in the product list, select products → More actions → **Set supplier & lead time**.
>
> Uninstalling removes the access token immediately; all data is deleted 48 hours later (shop/redact).

## Listing draft (English)

Check each field's current character limit in the Partner Dashboard.

**App name:** Clear Stock

**Tagline / introduction:** Know when each product runs out and how much to reorder, with the math shown.

**Details:**
> Clear Stock forecasts the stock-out date of every variant and tells you when to reorder and how many.
> Every number comes with a plain-language explanation you can check and adjust: which sales days were
> used, out-of-stock days left out, seasonality, lead time and safety stock. Bundles count toward their
> components. Flat price, no share of your sales, no contract, and your price never goes up.

**Key features:**
- Stock-out date and reorder quantity for every variant
- "Why this number?" explanation for every forecast, adjustable
- Bundles counted toward their components
- Supplier lead times, minimum orders and case packs
- Move from Stocky: import your purchase order CSV
- One daily or weekly email, only when something needs ordering
- Purchase order export, ABC classes and "what if sales grow 20%?"

**Search terms:** inventory forecast, reorder, stock out, purchase order, demand planning, low stock alert

> Mentioning Stocky: the feature line names the import format, which is factual. If review asks to
> remove references to other apps, change it to "Import purchase order CSV files".
