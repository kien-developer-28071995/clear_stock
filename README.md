# Clear Stock (`clear_stock`)

Embedded Shopify app that forecasts stock-outs per variant, suggests reorder points and quantities, and sends email alerts. Every forecast is explainable and adjustable by the merchant.

**Stack:** Laravel 13 (PHP 8.4) · MySQL 8.4 · Redis · Horizon · Scheduler · React 19 + TypeScript + Vite · Polaris web components · App Bridge (CDN) · Admin GraphQL API `2026-07` · Docker Compose.

---

## Quickstart: clone → app running in your dev store (< 10 min)

**Requirements:** Docker Desktop, `make`, a [Shopify Partner account](https://partners.shopify.com) with a dev store. Node is only needed on the host to run Shopify CLI via `npx` (step 5).

### 1. Create the app in Shopify (2 min)
1. Open the [Dev Dashboard](https://dev.shopify.com/dashboard) → **Create app** → **Start from Dev Dashboard**, name it `Clear Stock`.
2. Copy the **Client ID** and **Client secret** from **Settings**.
3. In `shopify.app.toml`, set `client_id = "<your client id>"`.

### 2. Configure environment (1 min)
Backend, frontend and website each have their own env file:
```bash
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env
cp website/.env.example website/.env
```
In `backend/.env` fill in `SHOPIFY_API_KEY` (Client ID) and `SHOPIFY_API_SECRET` (Client secret). Everything else works as-is for dev (`make setup` also creates missing env files from the examples).

### 3. Start the stack (3–4 min on first build)
```bash
make setup
```
This builds images, installs Composer deps, generates `APP_KEY`, runs migrations and starts: `app` (PHP-FPM), `nginx` (:8080), `mysql`, `redis`, `horizon`, `scheduler`, `node` (Vite + HMR), `website` (marketing site, http://localhost:4321), `mailpit` (UI on http://localhost:8025).

### 4. Open a public HTTPS tunnel (30 s)
```bash
make tunnel
```
Starts a Cloudflare quick tunnel, then **automatically writes the URL** into:
- `backend/.env` and `frontend/.env` → `APP_URL`
- `shopify.app.toml` → `application_url` and `[auth] redirect_urls`

Vite's dev server and HMR websocket are proxied through nginx on the same HTTPS origin, so hot reload works inside the Shopify admin iframe.

### 5. Push the config to Shopify and install (2 min)
```bash
make extensions                     # admin extension deps (npm workspace at the repo root)
npx @shopify/cli@latest app deploy  # app config + the three admin extensions
```
(First run asks you to log in and link the app.) Then in the Dev Dashboard open the app → **Test your app / Install** on your dev store. The app opens embedded in the admin and shows “Hello, <store name>”.

> **The quick tunnel URL changes every time the tunnel restarts.** Re-run `make tunnel` and `npx @shopify/cli@latest app deploy` after each restart.
> For a stable URL, create a [named Cloudflare tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/get-started/create-remote-tunnel/) pointing to `http://nginx:80`, put its token in `TUNNEL_TOKEN` (backend/.env), run `docker/scripts/set-app-url.sh https://your-host` once, then `make tunnel` uses the named tunnel.

> **Protected customer data:** `read_all_orders` and order access require requesting access in the Partner Dashboard (**API access → Protected customer data access**). For dev stores you can select the fields and continue; the app requests **no** customer fields (name, email, address).

---

## Make commands

| Command | What it does |
|---|---|
| `make setup` | First-time setup (build, deps, key, migrate, up) |
| `make up` / `make down` | Start / stop the dev stack |
| `make shell` | Shell in the PHP container |
| `make migrate` / `make fresh` | Run migrations / rebuild the DB |
| `make test` | Run the backend Pest test suite |
| `make typecheck` | Type-check the frontend |
| `make website-build` | Check and build the marketing website into `website/dist` (dev server: http://localhost:4321) |
| `make logs` (`s=horizon`) | Tail logs (all or one service) |
| `make tunnel` | Start HTTPS tunnel + write URL to both env files and `shopify.app.toml` |
| `make extensions` | Install admin extension deps, copy the forecast translations into them, type-check |
| `make e2e` | Playwright end-to-end tests (every plan + admin extensions) |
| `make restart` | Restart Horizon + scheduler (they keep code in memory) |
| `make artisan c="route:list"` | Run an artisan command |
| `make prod-build` / `prod-up` / `prod-migrate` | Production image build / start / migrate |

Horizon dashboard: http://localhost:8080/horizon (open in local env; basic auth via `HORIZON_BASIC_AUTH_*` elsewhere).

---

## Access scopes (and why)

| Scope | Why the app needs it |
|---|---|
| `read_products` | Read variants, SKUs, unit cost and bundle composition to forecast per variant. |
| `read_inventory` | Read inventory items and available quantity per location (current stock, days of cover). |
| `read_locations` | Which locations are active (stock counts only active ones), location names, and multi-location forecasts (Growth). |
| `read_orders` | Read line-item quantities and refunds to build **daily sales totals per variant**. |
| `read_all_orders` | Forecasts need more than the default 60 days of orders: up to 1 year for the 90-day window and the seasonality factor (same period last year). |
| `read_merchant_managed_fulfillment_orders` | Growth plan, per-location forecasts: which of the merchant's locations each order line is fulfilled from. Only requested in the orders export for Growth shops with 2+ locations. |
| `read_third_party_fulfillment_orders` | Same, for locations run by a fulfillment service (3PL), so their demand is counted too. |

**Optional scope** (declared in `optional_scopes`, not requested at install):

| Scope | Why |
|---|---|
| `write_inventory_transfers` | Growth, **Transfers** page: create a *draft* inventory transfer from a suggestion. Asked in the app (`shopify.scopes.request`) the first time the merchant clicks "Create draft transfer"; declining only disables that button. |

Apart from creating draft transfers the merchant asked for, the app is **read-only**: it never modifies products, inventory or orders (a draft transfer changes no stock until the merchant ships it in Shopify). Raw orders are not stored: only aggregated daily units sold/returned per variant. No customer names, emails or addresses are requested or stored.

---

## Authentication model

- **Shopify-managed installation** (`use_legacy_install_flow = false`): Shopify shows the consent screen, then opens the app embedded. No redirect-based OAuth.
- On first load, Shopify passes an `id_token`; the backend immediately performs **token exchange** for an **expiring offline access token** (+ refresh token), stored encrypted in `shops`.
- Every API call from the frontend carries a fresh App Bridge **session token** (`Authorization: Bearer`, from `shopify.idToken()`); `VerifyShopifySessionToken` validates signature, `exp`/`nbf`, `aud`, `iss`/`dest`. No cookies are used anywhere.
- Invalid/expired session tokens get `401` + `X-Shopify-Retry-Invalid-Session-Request: 1` so App Bridge retries with a new token.
- Background jobs use `ShopTokenService::accessToken()`, which refreshes the offline token under a per-shop lock before it expires.
- The embedded page sends `Content-Security-Policy: frame-ancestors https://{shop} https://admin.shopify.com`.

---

## Data model & webhooks

**Tables** (every shop-owned table has `shop_id`, FK with cascade delete):
`shops`, `suppliers`, `locations`, `variants`, `bundle_components`, `inventory_levels`, `daily_sales` (one row per variant per day: units sold/returned, end-of-day stock, `was_in_stock`), `forecasts`, `forecast_overrides`, `alert_settings`, `alert_logs`.
No raw orders and no customer data (names, emails, addresses) are stored.

Inside an authenticated request, the `BelongsToShop` trait scopes every shop-owned model to the current shop and fills `shop_id` automatically. Jobs have no request context and must use `->forShop($shop)`.

**Webhooks**: one endpoint `POST /webhooks`, declared in `shopify.app.toml`, routed by `X-Shopify-Topic`:

| Topic | What happens |
|---|---|
| `app/uninstalled` | Tokens dropped, plan reset to Free. Data kept until `shop/redact`, so a quick reinstall keeps history. |
| `app/scopes_update` | Granted scopes stored. |
| `customers/data_request` | Logged (ids only). The app stores no customer data, so there is nothing to return. |
| `customers/redact` | Logged (ids only). Nothing to erase. |
| `shop/redact` | All rows of the shop deleted in batches, then the shop itself. Skipped if the shop has reinstalled since. |

Every delivery is HMAC-verified (`401` if invalid), de-duplicated by `X-Shopify-Webhook-Id`, answered `200` immediately and processed on the `webhooks` Horizon queue (5 attempts, backoff 10s → 15min, permanent failures logged).

Trigger a test delivery (after `npx @shopify/cli@latest app deploy`):
```bash
npx @shopify/cli@latest app webhook trigger --topic app/uninstalled --api-version 2026-07 --address "$APP_URL/webhooks" --client-secret "$SHOPIFY_API_SECRET"
```

---

## Sync (Shopify → daily sales)

```
install ─► ShopInstalled ─► SyncService::start (initial: 365 days)        hourly: sync:nightly (02:00 shop time: last 30 days)
                               │
StartSyncRun ─► locations (regular query) + 3 concurrent bulk operations: variants, inventory, orders
CheckSyncRun ─► polls bulkOperation(id:) every 5–20 s (bulk_operations/finish webhook triggers it immediately)
ProcessSyncRun ─► download JSONL ─► variants ─► inventory ─► daily_sales ─► out-of-stock days ─► ShopSynced
```

- **Nothing raw is stored:** order line items are aggregated in memory into `daily_sales` (units sold, units returned) per variant per day in the shop's timezone. Cancelled orders are ignored. Refunded or removed units count as returns.
- **Incremental after the first sync:** the nightly run re-aggregates the last 30 days (catches late refunds and cancellations) and fetches only variants updated since the last sync. The whole catalog is refreshed on Mondays, plus a full inventory snapshot every night.
- **Out-of-stock days:** Shopify has no inventory history, so stock is walked backwards from today's level (`start = end + sold − returned`). Restocks are unknown, so this is an upper bound: an estimate ≤ 0 means the variant really was out of stock. Every sync also records yesterday's real end-of-day stock, and those snapshots override the estimate.
- **Native Shopify bundles:** components are imported as `bundle_components` (source `shopify`). Orders already contain the component lines, so component demand is counted directly and the bundle's own sales come from `lineItemGroup`.
- **Reliability:** runs are stored in `sync_runs` (stage, progress, bulk operation ids, stats, error). Transient Shopify errors are retried with backoff. Permanent errors mark the run failed and show a merchant-friendly message. `sync:maintenance` fails runs stuck for more than 6 h and prunes runs older than 30 days.
- **Queues:** sync jobs run on the `sync` queue of the `redis-long` connection (Horizon `sync-supervisor`, 1 h timeout), separate from webhooks.
- **API:** `GET /api/sync` returns the status and progress; `POST /api/sync` runs "Sync now" (5-minute cooldown).

---

## Forecast engine

Pure calculator (`app/Services/Forecast/ForecastCalculator.php`, no DB access) plus loader/service around it. Tuning lives in `config/forecast.php`.

1. **Daily demand** = units sold − units returned, plus units sold inside merchant-defined (manual) bundles × quantity per bundle. Native Shopify bundles are not added again, because orders already contain their component lines.
2. **Sales rate:** averages over the last 7 / 30 / 90 days counting **in-stock days only** (out-of-stock days are excluded and reported). A window needs 4 / 10 / 20 in-stock days to be used. Weights are 20% / 50% / 30%, re-normalised over the usable windows.
3. **Seasonality:** last year, next 28 days vs previous 28 days (4 full weeks, so weekly order patterns don't look seasonal). It is applied only with ≥ 70% in-stock days and ≥ 10 units last year, and only when the change exceeds ±10%. It is clamped to ×0.5 – ×2.5.
3b. **New products (Starter+):** a product can borrow a **similar product's** rate (× a percentage, default 100%). The estimate blends the two by the product's own in-stock days: own weight = days / 30 (`forecast.reference_full_after_days`), so it is fully its own after 30 days. The reference's current store-wide rate is used; per-location forecasts ignore it.
4. **Overrides:** a merchant override of the sales rate replaces the computed rate (and any reference). Lead time resolves as override → SKU → supplier → store default (14 days). Safety days resolve as override → SKU → store default (7 days). Expired overrides are ignored.
5. **Reorder maths:** reorder point = rate × (lead time + safety days). Reordering uses the **stock position** = on hand + on the way (Shopify's `incoming` quantity: purchase orders and transfers created in Shopify). Suggested qty = rate × (lead time + safety + 30-day order cycle) − stock position, and the reorder date is when the stock position reaches the reorder point. Shopify gives no arrival date for incoming stock, so days of cover (= on hand ÷ rate) and the stock-out date use on-hand stock only.
   Per product (or as a supplier default that products without their own inherit), merchants can also set a **minimum order quantity** and **pack size** (the order is raised to the minimum, then rounded up to whole packs; never changes when to order), and a manual **min / max** as in Stocky (min replaces the reorder point, max the order-up-to level; products without sales reorder only through min). Min/max apply to the store-wide forecast, not per location.
   **Overstock:** the order-up-to level (lead time + safety + order cycle, or the merchant's max) is stored as `target_stock`, and stock + on the way above it as `excess_units`. A product that still sells and holds more than 50% above its level (`forecast.overstock_ratio`) is *Overstock* (status order: out of stock → reorder now → slow → overstock → OK); the Insights page shows the excess and its cost value.
6. **Confidence:** *low* with fewer than 14 in-stock days or fewer than 5 units in 90 days, or when the weekly coefficient of variation is above 1.0. *high* with ≥ 60 in-stock days, ≥ 30 units and weekly CV ≤ 0.5. Anything else is *medium*.

Every intermediate number is stored in `forecasts.explanation` (JSON). `ExplanationFormatter` turns it into sentences, for example: *"Sells 4/day over the last 30 days (3 out-of-stock days left out). Lead time 14 days (store default) + 7 safety days → reorder point 84 units. 100 in stock runs out around Oct 15 → order 104 units by Sep 24. Confidence: high."*

**When it runs:** after every successful sync (`ShopSynced` → `RecomputeForecasts`, so nightly in each shop's timezone). A safety net, `forecast:nightly`, runs at 05:00 shop time and recomputes forecasts older than 20 h. Tools: `make artisan c="forecast:run"`, `make artisan c="forecast:show --limit=10"`, `make artisan c="sync:run --full"` (re-import 400 days, e.g. after historical orders were imported).

**Dev data:** `make artisan c="dev:fake-orders"` creates about 110 backdated test orders (tag `clear-stock-fake`) in a development store, with one sales scenario per variant. `--purge` deletes them. This needs the dev-only `write_orders` scope.

---

## Embedded app (frontend)

| Screen | What it shows |
|---|---|
| **Onboarding** (first open) | Import progress + two questions: default lead time (pre-filled 14 days) and alert email (pre-filled with the store's contact email). |
| **Home** ("aha") | Only what needs attention: four numbers (out of stock, reorder now, order this week, slow-moving stock), the five most urgent products with a one-line reason, the setup guide for new shops, and the sync card while a sync runs or failed. |
| **Reorder** | Everything to reorder in the next 7 days, grouped into *Out of stock*, *Order today* and *Order this week*. Today's rows start selected, so "Export purchase order" produces today's order in one click (Growth). |
| **Insights** | Stock runway chart (days of stock left vs each product's lead time + safety line) and cash tied up in slow stock. |
| **Setup guide + tips** | A 5-step guide on Home (Shopify onboarding guidance: ≤ 5 steps, auto-completed, progress, dismissible): import data, default lead time, see why a product needs reordering, suppliers (skippable), alerts (skippable; points to Plans on Free). Steps complete from real data or recorded events (`shops.setup_guide` JSON). Three one-time contextual tips (home actions, runway, product explanation) are dismissible and remembered per shop. A dismissed guide can be brought back from Settings. |
| **Products** | Every tracked variant: status, stock, sales/day, days left, order-by date, suggested quantity. Search, status filter and sort live in the URL. |
| **Product detail** | Summary, "Why these numbers?" (sentences + per-window table + seasonality), a temporary sales-rate adjustment (optional end date), per-product supplier / lead time / safety days. |
| **Suppliers / Bundles / Settings** | Supplier CRUD + assign products (Shopify resource picker), manual bundles (resource picker; Shopify Bundles shown read-only), store defaults and alert preferences. |

UI: Polaris web components (`s-*`) + App Bridge (title bar, nav menu, toasts, resource picker). Data: React Query. Links rendered by Polaris (`href`) are routed client-side via the `shopify:navigate` event (`useShopifyNavigation`).

API (session-token authenticated): `GET /api/dashboard`, `GET|POST /api/onboarding`, `GET /api/forecasts`, `GET /api/forecasts/{variant}`, `PUT /api/forecasts/{variant}/overrides`, `PUT /api/variants/{variant}/settings`, `PUT /api/variants/settings` (bulk), `GET /api/variants?search=`, `GET|PUT /api/settings`, `GET|POST|PUT|DELETE /api/suppliers`, `GET|POST|DELETE /api/bundles`. Ids in URLs are always resolved through shop-scoped repositories.

**Caching:** the dashboard queries are cached per shop under a *forecast version* key that is bumped on every forecast write, so they never go stale. Supplier lists, alert settings, the shop and the latest sync run are cached too and invalidated by model observers. Laravel 13 only unserializes allow-listed classes from the cache (`config/cache.php` → `serializable_classes`). **Add any new model a Cache repository stores to that list.** Tests serialize the array cache (`CACHE_ARRAY_SERIALIZE=true`) so a missing class fails a test instead of production.

---

**Public pages** (home, `/privacy`, `/support`, the App Store listing URLs) live on the marketing website, see [Website](#website). The app's own `/privacy` and `/support` answer `301` to `WEBSITE_URL` (keeping `?lang=vi` as `/vi/...`), and `/` without a `shop` redirects to the website home, so old links and sent emails keep working.

## Website

`website/` is the marketing site: an [Astro](https://astro.build) project that builds plain static HTML (no framework JavaScript, fast and indexable). Pages: home (features, how it works, screenshots, pricing, FAQ), `/privacy`, `/support` and a 404, each in the app's six languages (English at `/`, others under `/vi`, `/es`, `/de`, `/fr`, `/pt`), plus `sitemap.xml` and `robots.txt`.

- **Text:** `website/src/i18n/locales/<lang>.json` (the privacy policy and support FAQ are the `legal` section). `npm run i18n:check` (part of `npm run build`) fails on a missing key or a lost `{{placeholder}}`. Bump `PRIVACY_UPDATED` in `website/src/config.ts` when the policy changes, and keep the policy true to what the backend stores.
- **Settings** (`website/.env`, read at build time): `SITE_URL`, `APP_NAME`, `SUPPORT_EMAIL`, `INSTALL_URL` (the App Store listing, for every Install button), `GROWTH_OFFERED`. In production the Deploy workflow fills them from the same GitHub Variables as the app (`WEBSITE_URL`, `SHOPIFY_APP_NAME`, `SUPPORT_EMAIL`, `INSTALL_URL`, `BILLING_GROWTH_OFFERED`).
- **Prices** shown on the site mirror `backend/config/billing.php` in `website/src/config.ts`: change both together.
- **Screenshots** in `website/public/screenshots` are copies of `docs/listing/screenshots` (re-copy after `make listing-screenshots`).
- **Run:** `make up` starts it with hot reload on http://localhost:4321 (or `cd website && npm install && npm run dev`); `make website-build` checks and builds `website/dist`. Production: Caddy serves the built files (deploy/Caddyfile, docs/DEPLOY.md).

## Owner reports (`admin/`)

A separate plain Laravel + Blade app for the app owner only: how many shops have the app installed, how many uninstalled, which shop uses which feature, and whether syncs and emails are healthy. It only reads this app's database and keeps its own SQLite ledger of installs, uninstalls and plan changes (the app deletes a shop 48 hours after an uninstall, so that history would otherwise be lost). Dev: `make admin-setup`, `make admin-user EMAIL=you@example.com`, then http://localhost:8090. Details, security and what to build next: [admin/README.md](admin/README.md).

## Languages

The app is translated into English, Vietnamese, Spanish, German, French and Portuguese (Brazil) (react-i18next). It follows the Shopify admin language; merchants can override it in **Settings → Language** (saved on the shop).

- All UI text lives in the frontend: `frontend/src/i18n/locales/<lang>.json`. The API never returns sentences; it returns snake_case codes with raw params (errors `{code, params}`, validation `errors.<field>[{code, params}]`, forecast `explanation_lines`, sync `stage` and `error`), which the app translates.
- **Add a language:** copy `en.json` to e.g. `fr.json`, translate, add `'fr'` to `supported_locales` in `backend/config/app.php`. `npm run i18n:check` (also part of `npm run build`) fails on missing keys or plural forms.
- Alert emails are English only.

## Stock transfers between locations (Growth)

**Transfers** page (`/transfers`): move stock you already have before ordering more.

- **Planner** (`app/Services/Transfer/TransferPlanner.php`, pure, unit-tested): per product and location, from the per-location forecasts. A location *needs* stock when it sells and its stock position (on hand + on the way) is at or below its reorder point; it needs enough to reach its order-up-to level. A location *can spare* what it holds above the level it should keep (its order-up-to level, or everything if the product doesn't sell there), on hand only. Needs are served earliest stock-out first, from the locations with the most to spare.
- **Routes**: suggestions grouped "from A → to B", quantities editable. **Create draft transfer in Shopify** calls `inventoryTransferCreate` (with an `@idempotent` key from the app, so a retried click never creates two). The merchant reviews and ships it in Shopify › Products › Transfers.
- Drafts created in the last 7 days (`inventory_transfers`) count as moved, so the same stock isn't suggested twice. The Reorder page shows how many units can come from other locations.
- Needs forecasts per location (Growth, 2+ active locations, fulfillment order scopes); otherwise the page explains why.

## Admin extensions (Shopify product pages)

Three [admin UI extensions](https://shopify.dev/docs/api/admin-extensions) in `extensions/` (Preact, API 2026-07), deployed with `app deploy`:

| Extension | Where | What |
|---|---|---|
| `product-forecast-block` | Product page (`admin.product-details.block.render`) and variant page (`admin.product-variant-details.block.render`) | Each variant's status, stock (with what is on the way and marked as ordered), sales/day, stock-out date, order-by date and suggested order; the plain-language "why" of the most urgent variant; link into the app (`app:products/{id}`). On a variant page, just that variant. |
| `product-settings-action` | Product list → select → **More actions** (`admin.product-index.selection-action.render`) and product page → **More actions** (`admin.product-details.action.render`) | Reorder settings on every variant: supplier, lead time, safety days, minimum order, pack size, discontinued. |
| `product-order-action` | Product and variant page → **More actions** (`admin.product-details.action.render`, `admin.product-variant-details.action.render`) | Mark as ordered (an order placed outside Shopify): quantities start at the suggested order, optional expected date and reference; counted as on the way in the app. |

- **Backend:** `GET /api/extension/products/{shopifyProductId}`, `GET /api/extension/variants/{shopifyVariantId}`, `POST /api/extension/product-settings` and `POST /api/extension/manual-orders` (`ProductExtensionController` → `ProductExtensionService`). Extensions call relative `api/...` URLs; Shopify resolves them against `application_url` and adds the ID token, verified by the same middleware as the app. They run on Shopify's extension domain, so `config/cors.php` allows cross-origin calls to `api/*` (bearer tokens only, no cookies).
- **Translations:** extension locale files hold their own strings; the `status`, `confidence` and `explanation` sections are copied from `frontend/src/i18n/locales` by `npm run extensions:locales` (i18next plurals → Shopify plural objects). `make e2e` fails if they are out of date.
- **Layout:** the repo-root `package.json` is only the Shopify CLI workspace for the extensions (the CLI installs dependencies from the app root); backend and frontend keep their own.
- **Tests:** `frontend/e2e/extensions.spec.ts` runs the real bundles against the API with Shopify's extension host stubbed. To see them in the admin, run `npx @shopify/cli@latest app dev` (or deploy) and open a product.

## Feature switches (app-wide)

`backend/config/features.php` switches optional features on or off **for every shop**, whatever the plan (e.g. a trimmed first App Store submission). Env vars, all `true` by default:

| Env | Feature | Notes |
|---|---|---|
| `FEATURE_WHAT_IF` | Sales what-if page | |
| `FEATURE_REFERENCE_PRODUCTS` | New products forecast from a similar product | forecasts ignore saved references while off |
| `FEATURE_ABC` | ABC classes (column, filter, sort, Insights) | still computed, just hidden |
| `FEATURE_PURCHASE_PLAN` | Purchase plan page (12 weeks of orders and spend, Starter) | |
| `FEATURE_SPIKE_FILTER` | One-off sales spikes capped before averaging (every plan; merchants can also turn it off in Settings) | forecasts use raw sales while off |
| `FEATURE_LOST_SALES` | Sales lost to stock-outs (Insights, explanation line) | still computed, just hidden |
| `FEATURE_ORDER_BUDGET` | Monthly order budget and priorities (Starter) | the saved budget is kept |
| `FEATURE_SALES_EVENTS` | Sales events (promotions raising/lowering demand on set days) | saved events are ignored by forecasts while off |
| `FEATURE_ACCURACY` | Forecast accuracy (Insights, product page): each week's forecast vs what really sold | weekly snapshots are still saved |
| `FEATURE_PURCHASE_ORDERS` | Purchase order CSV export | |
| `FEATURE_SUPPLIER_EMAILS` | Emailing orders to suppliers (by hand and automatic) | |
| `FEATURE_LOCATIONS` | Per-location forecasts | needs `read_*_fulfillment_orders`; when switched back on, run `sync:run --full` for Growth shops |
| `FEATURE_TRANSFERS` | Transfer suggestions | needs locations; uses the optional `write_inventory_transfers` scope |
| `FEATURE_REALTIME_ALERTS` | Real-time stock alerts | the per-shop inventory webhook is removed by the nightly `alerts:realtime-sync` |
| `FEATURE_FLOW_TRIGGERS` | Shopify Flow triggers | also leave the `flow-*` extensions out of the deploy |
| `BILLING_GROWTH_OFFERED` | Growth on the pricing page | off = no new Growth subscriptions; existing ones keep working |

How it is safe:
- **One gate.** `Entitlements::has()` = plan **and** switch, so every API, job, webhook, listener and scheduled command that already checked the plan also respects the switch. Locked APIs answer `404 feature_disabled` (not the `402` upgrade answer).
- **Hidden, not upsold.** `/api/shop` returns `entitlements.features`; the app drops the menu entry, page (404), buttons, settings, upgrade prompts and pricing rows of a switched-off feature (`useFeature()`).
- **Nothing is deleted.** Settings and data stay; switching back on restores the feature.
- **Core features have no switch:** forecasts, explanations, bundles, alerts, suppliers.
- Production caches config: restart after changing a switch. Check with `php artisan features:status` (fails on combinations that can't work: a missing scope for a switched-on feature, or Growth offered with nothing Growth-only left). `make e2e-features-off` runs an E2E check with switches off.

## Shopify Flow triggers (Growth)

Three `flow_trigger` extensions (`extensions/flow-*`) let merchants start their own Flow workflows from the forecast:

| Trigger (handle) | Fires | Main fields |
|---|---|---|
| Product reorder date reached (`product-reorder-date-reached`) | once when a product's reorder date is today or past; again only after it stopped being due | product, SKU, suggested quantity, reorder date, days until stock-out, supplier, ABC class, app URL |
| Product stockout threshold reached (`product-stockout-threshold-reached`) | once per threshold as days until stock-out crosses 30, 14, 7, 0; re-armed when stock is clearly back above (20% + 2 days) | same + `Threshold days` (use it in a Flow condition) |
| Supplier reorder date reached (`supplier-reorder-date-reached`) | when a product of the supplier becomes due | supplier name/email, products due, new products due, total units and cost, order lines |

- Sent after every forecast run (`ForecastsUpdated` → `SendFlowTriggers`, 1 min delay, unique per shop) with `flowTriggerReceive`. At most 250 per run; unsent ones and failures keep their old state and go out on the next run. What each trigger last saw is in `flow_trigger_states`.
- Only for shops with an **active workflow**: the `flow-lifecycle` extension (`flow_trigger_lifecycle_callback`) posts to `/flow/lifecycle` (HMAC like webhooks) when a workflow is turned on/off; stored in `flow_subscriptions`, newest `timestamp` wins. Its URL is absolute and rewritten by `make app-url`.
- **Test in a dev store:** Deploy with `npx @shopify/cli@latest app deploy` (or `app dev` for drafts), install Shopify Flow, create a workflow with a Clear Stock trigger (e.g. → "Send internal email"), turn it on (the lifecycle callback records it), then `php artisan sync:run` or change a product's lead time so its reorder date is today.

## Importing from Stocky (purchase order CSVs)

Stocky can't export suppliers, only purchase orders. **Suppliers → Import from Stocky** (`/suppliers/import`) rebuilds them from one or more purchase order CSVs (Stocky, other apps, spreadsheets):

- Columns are detected from header aliases (`app/Services/Import/PurchaseOrderCsv.php`); date columns must contain dates. The merchant can re-map any column in the app. Comma, semicolon and tab delimiters and a UTF-8 BOM are handled.
- Suppliers: one per supplier name (reusing existing ones, case-insensitive). Lead time: median days from order date to received date (or expected date when not received), per purchase order.
- Products are matched by Shopify variant ID, then SKU, then product/variant name. Each product is linked to the supplier it was ordered from most recently. Products that already have a supplier are kept unless the merchant ticks "replace".
- Two stateless endpoints (the app sends the files twice): `POST /api/imports/purchase-orders/preview` and `/apply`. Nothing is stored before apply. Forecasts are recomputed afterwards.

## Plans, billing and alerts

| | Free | Starter $4/mo ($38/yr), 7-day trial | Growth $6/mo ($58/yr), 7-day trial |
|---|---|---|---|
| Forecasts + reorder suggestions | 50 best sellers | Unlimited | Unlimited |
| "Why this number?" explanations, ABC classes | ✓ | ✓ | ✓ |
| Bundles | – | ✓ | ✓ |
| Email alerts (summary) | – | ✓ | ✓ |
| Purchase order CSV, emailing an order to a supplier | – | ✓ | ✓ |
| Sales what-if | – | ✓ | ✓ |
| Forecast per location, transfer suggestions | – | – | ✓ |
| Automatic weekly orders to suppliers, real-time alerts, Shopify Flow triggers | – | – | ✓ |

- Plans and limits live in `config/billing.php`. `App\Support\Entitlements::for($shop)` is the only place that decides access. Locked API features answer `402` with `required_plan`.
- **Billing API:** `appSubscriptionCreate` (`EVERY_30_DAYS` / `ANNUAL`, `replacementBehavior: STANDARD`). The merchant approves on Shopify's page and returns to `/plans?confirmed=1`, which re-reads `currentAppInstallation.activeSubscriptions`. `app_subscriptions/update` webhooks keep the plan in sync (e.g. a cancel from the Shopify admin). Downgrading to Free cancels the subscription. Plan and interval are derived from the subscription name (`"<App> Starter (monthly)"`).
- **Free trial:** 7 days on paid plans (`SHOPIFY_BILLING_TRIAL_DAYS`), granted **once per shop**. `shops.trial_started_at` is set when the first paid plan activates. Later subscriptions (switching plans, cancel and re-subscribe) only get the days left.
- `SHOPIFY_BILLING_TEST=true` creates test charges (development stores only accept these). **Set it to `false` in production.**
- **Per-location forecasts (Growth):** for shops with 2+ active locations and the fulfillment order scopes, the orders export also reads each order's fulfillment orders (`assignedLocation` + line quantities) into `location_daily_sales`. Out-of-stock days are detected per location from that location's stock, and the same engine runs per location (`forecasts.location_id`). Lead time and safety settings apply per location; a sales-rate override applies to the store total only. Upgrading to Growth triggers a full 400-day resync. Downgrading removes location forecasts.
- **Alerts:** `alerts:send` runs hourly. Each shop gets one daily or weekly summary from 08:00 shop time, and only when at least one product is new since the last summaries: not alerted in the past 7 days, or escalated from "reorder soon" to "out of stock". Nothing is sent when nothing is new, and never more than once a day. Emails are queued; in dev they land in Mailpit (http://localhost:8025).

---

## Project layout

Backend and frontend are separate projects; the repo root only holds infra.

```
/                         docker-compose.yml, docker-compose.prod.yml, Dockerfile, Makefile,
│                         shopify.app.toml, docker/ (nginx, php, scripts)
├── extensions/           Shopify admin UI extensions (product page block, product list action) + shared/ helpers
├── backend/              Laravel 13 — API, webhooks, embedded page shell (composer.json, artisan, tests/, .env)
│   ├── app/
│   │   ├── Http/Controllers/Api, Http/Middleware, Http/Resources
│   │   ├── Models/                 Shop, …
│   │   ├── Repositories/Contracts|Eloquent|Cache   (Cache decorates Eloquent)
│   │   ├── Services/               ShopAuthService, ShopService, Shopify/* (OAuth, tokens, Admin API)
│   │   ├── Observers/              cache invalidation
│   │   └── Support/CacheKeys.php   every cache key lives here
│   └── resources/views/app.blade.php   loads App Bridge, Polaris and the Vite bundle
├── frontend/             React 19 + TypeScript + Vite (package.json, vite.config.ts, .env)
│   └── src/
│       ├── app/                    App, providers, router
│       ├── features/<module>/      api, hooks, components, pages, types.ts
│       └── components/ui|layout, lib/ (http client with session token, queryClient)
└── website/              Marketing site, static Astro (package.json, astro.config.mjs, .env): home, /privacy, /support
    └── src/                        pages/[...lang]/, components/, layouts/, i18n/locales/, config.ts
```

- **Separate env files:**
  - `backend/.env`: Laravel config (Shopify, DB, Redis, mail, `APP_URL`). The `mysql` container maps its `DB_*` values to `MYSQL_*`, and the named tunnel reads `TUNNEL_TOKEN` from it, so credentials are never duplicated.
  - `frontend/.env`: Vite only (`APP_URL` for the dev server/HMR origin). Only `VITE_*` variables reach browser code; never put secrets here.
  - `website/.env`: build settings of the static website (all of it ends up in public HTML; never put secrets here).
  - Host ports use compose defaults (`8080`, `33060`, `8025`); override per run, e.g. `APP_PORT=8090 make up`.
- The frontend builds into `backend/public/build`; in dev, Vite writes `backend/public/hot` so Laravel serves HMR assets.
- Request flow in the backend: Controller → Service → Repository (Cache → Eloquent) → Model.
- Running tools outside Docker: `cd backend && composer install && php artisan test`, `cd frontend && npm install && npm run dev`.

---

## Emailing purchase orders to suppliers (Starter by hand, Growth automatic)

- **Merchant-sent:** Suppliers → *Email order*. The dialog lists the supplier's products due now with the suggested quantities; the merchant edits quantities, removes lines, adds a message and the reply-to address, then sends. `GET/POST /api/suppliers/{id}/email`.
- **Automatic (opt-in per supplier):** tick *Email purchase orders automatically* on the supplier. `suppliers:send-orders` runs hourly; from 8am shop time, if the supplier has products due and the forecast is fresh, their purchase order goes out, at most once per `alerts.supplier_auto_interval_days` (7).
- The email is from "{store} via Clear Stock" with **Reply-To the merchant** (alert email, else the store contact email), lists SKU / product / quantity and attaches the same list as CSV. Every send is logged in `supplier_emails` (history, last-emailed date, weekly limit). A double click can't send twice (60 s lock).

## Downgrades

Before a move to a smaller plan, the Plans page shows what stops working **for this shop** (`GET /api/billing/impact?plan=`, codes + the shop's numbers): products beyond the SKU limit, bundles set up, active alert emails, locations, purchase orders, suppliers with automatic orders. Nothing is deleted on downgrade: settings are kept and apply again on upgrade. Forecasts are recomputed right after the plan changes (SKU limit, bundle demand, per-location forecasts removed). Cancelling from the Shopify admin (webhook) applies the same rules without the dialog.

**Missed billing webhooks:** `billing:reconcile` runs daily (04:20 UTC) and re-reads every installed shop's active subscription from Shopify (`BillingService::reconcile`). A drift is fixed (plan, interval, subscription id, forecasts recomputed) and logged as a warning (`Billing drift fixed (missed webhook?)`). The Plans page also re-reads the subscription right after the merchant approves a charge.

## Queues (Horizon)

| Supervisor | Queue | Jobs | Processes (local / production) |
|---|---|---|---|
| `webhooks-supervisor` | `webhooks` | Shopify webhooks, incl. frequent `inventory_levels/update` (real-time alerts) | 1 / up to 5 |
| `mail-supervisor` | `mail` | every email (`QueuedMailable`) | 1 / 2 |
| `supervisor-1` | `default` | forecasts, alert checks, billing reconciliation, supplier orders | 3 / up to 10 |
| `sync-supervisor` | `sync` (`redis-long`) | bulk operation downloads and imports | 2 / 4 |

Each queue has a wait threshold (`waits` in `config/horizon.php`: webhooks 30 s, default 60 s, mail 120 s, sync 600 s). A backed-up queue fires Horizon's `LongWaitDetected`, logged as an error, so it reaches Slack.

## Business events (Slack)

Installs, uninstalls, upgrades, downgrades and billing-interval changes are posted to a **separate** Slack channel (`MONITORING_SLACK_EVENTS_WEBHOOK_URL`; empty = off), kept apart from errors. Events: `ShopInstalled` (new install / reinstall), `ShopUninstalled` (with the plan the shop was on), `PlanChanged` (fired by `BillingService` whether the change came from the app, a webhook or the daily reconciliation, once per change). Messages show the shop name and domain, the plans with prices, the MRR change and the number of active installs. They are posted by a queued job (`PostShopEventToSlack`, 3 tries), so Slack never slows an install or a billing change.

## Email log

All emails extend `App\Mail\QueuedMailable`: they run on the dedicated `mail` queue (Horizon `mail-supervisor`, so forecast and sync work never delays them) with 3 tries (retry after 1 and 5 minutes). A test fails if a new mailable doesn't extend it.

Every email the app sends is logged in `email_logs`, whatever feature sent it (reorder digest, supplier purchase orders, anything added later): a listener on Laravel's `MessageSent` event writes `sent` rows, and one on `JobFailed` writes `failed` rows for queued emails that gave up after their retries (the exception also goes to Slack monitoring). Rows hold metadata only: mailable class, shop, from / to / cc / bcc / reply-to, subject, attachment names, message id, error. Never the body. Kept `EMAIL_LOG_RETENTION_DAYS` (180) days (`model:prune`, daily) and deleted with the shop on `shop/redact`.

## Error monitoring (Slack)

Errors are posted to one Slack channel through an incoming webhook (`MONITORING_SLACK_WEBHOOK_URL`; empty = off). Test it with `make artisan c="monitoring:test"`.

- **What is sent:** every log record at `error` or above, whatever the log channel (listener on Laravel's `MessageLogged`): unhandled exceptions in requests, jobs and commands, jobs that failed permanently, failed syncs, errors caught in `try/catch` via `App\Support\Monitor::caught()`, and frontend crashes (JavaScript errors and React render errors, posted by the app to `POST /api/client-errors`).
- **What is not:** expected outcomes (`ApiException` 4xx, validation, plan limits, invalid session tokens) and handled, expected conditions logged with `Monitor::expected()` (warnings; set `MONITORING_SLACK_LEVEL=warning` to include them).
- **Each alert shows** the environment, message, exception class, file:line, app stack frames, and where it happened (shop domain, `METHOD /path` without query string, job or command). Only whitelisted context keys are included, and tokens/secrets are masked. No customer data.
- **No spam:** the same error (class + place, or message shape) is posted at most once per `MONITORING_THROTTLE_SECONDS` (10 min); the next alert says how many repeats were suppressed. Posting happens after the HTTP response is sent and never throws.

## Production

```bash
docker compose -f docker-compose.prod.yml build     # or: make prod-build
docker compose -f docker-compose.prod.yml up -d     # or: make prod-up
make prod-migrate                                   # by hand (CI/CD deploys run additive migrations itself)
```
- Multi-stage `Dockerfile`: frontend build (Node) → `composer install --no-dev` → slim PHP-FPM runtime (`app`), plus an nginx image (`web`) with the built assets.
- No source mounts, no Node, no Mailpit. `config:cache`, `route:cache`, `view:cache`, `event:cache` run at container start.
- `horizon` and `scheduler` use `restart: unless-stopped`; healthchecks on `app`, `web`, `mysql`, `redis`, `horizon`. Logs go to stdout.
- TLS must terminate in front of `web` (load balancer, Caddy, or Cloudflare Tunnel). Set `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_URL` and mail settings in `backend/.env` on the server (the frontend build needs no runtime env).
