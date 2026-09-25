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
Backend and frontend each have their own env file:
```bash
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env
```
In `backend/.env` fill in `SHOPIFY_API_KEY` (Client ID) and `SHOPIFY_API_SECRET` (Client secret). Everything else works as-is for dev (`make setup` also creates missing env files from the examples).

### 3. Start the stack (3–4 min on first build)
```bash
make setup
```
This builds images, installs Composer deps, generates `APP_KEY`, runs migrations and starts: `app` (PHP-FPM), `nginx` (:8080), `mysql`, `redis`, `horizon`, `scheduler`, `node` (Vite + HMR), `mailpit` (UI on http://localhost:8025).

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
npx @shopify/cli@latest app deploy
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
| `make logs` (`s=horizon`) | Tail logs (all or one service) |
| `make tunnel` | Start HTTPS tunnel + write URL to both env files and `shopify.app.toml` |
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
| `read_locations` | Multi-location forecasts (Growth plan) and location names in the UI. |
| `read_orders` | Read line-item quantities and refunds to build **daily sales totals per variant**. |
| `read_all_orders` | Forecasts need more than the default 60 days of orders: up to 1 year for the 90-day window and the seasonality factor (same period last year). |

The app is **read-only**: it never modifies products, inventory or orders. Raw orders are not stored: only aggregated daily units sold/returned per variant. No customer names, emails or addresses are requested or stored.

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

## Project layout

Backend and frontend are separate projects; the repo root only holds infra.

```
/                         docker-compose.yml, docker-compose.prod.yml, Dockerfile, Makefile,
│                         shopify.app.toml, docker/ (nginx, php, scripts)
├── backend/              Laravel 13 — API, webhooks, embedded page shell (composer.json, artisan, tests/, .env)
│   ├── app/
│   │   ├── Http/Controllers/Api, Http/Middleware, Http/Resources
│   │   ├── Models/                 Shop, …
│   │   ├── Repositories/Contracts|Eloquent|Cache   (Cache decorates Eloquent)
│   │   ├── Services/               ShopAuthService, ShopService, Shopify/* (OAuth, tokens, Admin API)
│   │   ├── Observers/              cache invalidation
│   │   └── Support/CacheKeys.php   every cache key lives here
│   └── resources/views/app.blade.php   loads App Bridge, Polaris and the Vite bundle
└── frontend/             React 19 + TypeScript + Vite (package.json, vite.config.ts, .env)
    └── src/
        ├── app/                    App, providers, router
        ├── features/<module>/      api, hooks, components, pages, types.ts
        └── components/ui|layout, lib/ (http client with session token, queryClient)
```

- **Separate env files:**
  - `backend/.env`: Laravel config (Shopify, DB, Redis, mail, `APP_URL`). The `mysql` container maps its `DB_*` values to `MYSQL_*`, and the named tunnel reads `TUNNEL_TOKEN` from it, so credentials are never duplicated.
  - `frontend/.env`: Vite only (`APP_URL` for the dev server/HMR origin). Only `VITE_*` variables reach browser code; never put secrets here.
  - Host ports use compose defaults (`8080`, `33060`, `8025`); override per run, e.g. `APP_PORT=8090 make up`.
- The frontend builds into `backend/public/build`; in dev, Vite writes `backend/public/hot` so Laravel serves HMR assets.
- Request flow in the backend: Controller → Service → Repository (Cache → Eloquent) → Model.
- Running tools outside Docker: `cd backend && composer install && php artisan test`, `cd frontend && npm install && npm run dev`.

---

## Production

```bash
docker compose -f docker-compose.prod.yml build     # or: make prod-build
docker compose -f docker-compose.prod.yml up -d     # or: make prod-up
make prod-migrate                                   # migrations never run automatically
```
- Multi-stage `Dockerfile`: frontend build (Node) → `composer install --no-dev` → slim PHP-FPM runtime (`app`), plus an nginx image (`web`) with the built assets.
- No source mounts, no Node, no Mailpit. `config:cache`, `route:cache`, `view:cache`, `event:cache` run at container start.
- `horizon` and `scheduler` use `restart: unless-stopped`; healthchecks on `app`, `web`, `mysql`, `redis`, `horizon`. Logs go to stdout.
- TLS must terminate in front of `web` (load balancer, Caddy, or Cloudflare Tunnel). Set `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_URL` and mail settings in `backend/.env` on the server (the frontend build needs no runtime env).
