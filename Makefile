# Developer shortcuts. Run `make help` to list them.
SHELL := /bin/bash
DC      := docker compose
DC_PROD := docker compose -f docker-compose.prod.yml
export UID := $(shell id -u)
export GID := $(shell id -g)

.DEFAULT_GOAL := help
.PHONY: help setup up down restart build shell migrate fresh test e2e extensions logs tunnel tunnel-url tunnel-down e2e-features-off listing-screenshots feature-screenshots listing-video \
        app-url webhook artisan composer npm typecheck website website-build admin-setup admin-user admin-test prod-build prod-up prod-down prod-migrate prod-logs

help: ## List available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

setup: ## First run: env files, build images, install deps, app key, migrate
	@test -f backend/.env || cp backend/.env.example backend/.env
	@test -f frontend/.env || cp frontend/.env.example frontend/.env
	@test -f website/.env || cp website/.env.example website/.env
	$(DC) build
	$(DC) run --rm --no-deps app composer install
	@grep -qE '^APP_KEY=.+' backend/.env || $(DC) run --rm --no-deps app php artisan key:generate --ansi
	$(DC) up -d --wait mysql redis
	$(DC) run --rm app php artisan migrate --ansi
	$(DC) up -d
	@echo "Done. Next: make tunnel"

up: ## Start the dev stack
	$(DC) up -d

down: ## Stop the dev stack (incl. tunnel)
	$(DC) --profile tunnel --profile tunnel-named down

restart: ## Restart workers (Horizon/scheduler cache code + config in memory)
	$(DC) restart horizon scheduler

build: ## Rebuild dev images
	$(DC) build

shell: ## Shell into the PHP container
	$(DC) exec app sh

migrate: ## Run migrations
	$(DC) exec app php artisan migrate

fresh: ## Drop all tables and re-run migrations
	$(DC) exec app php artisan migrate:fresh

test: ## Run the backend test suite (Pest)
	$(DC) exec app php artisan test

e2e: ## End-to-end tests in Chromium on every plan + admin extensions (needs make up + a synced dev store)
	npm run extensions:locales:check
	cd frontend && npx playwright test

# What the App Store version (v1) switches off: keep in step with backend/.env.production.example.
V1_OFF = \nFEATURE_SHOPIFY_PURCHASE_ORDERS=false\nFEATURE_SUPPLIER_EMAILS=false\nFEATURE_LOCATIONS=false\nFEATURE_TRANSFERS=false\nFEATURE_REALTIME_ALERTS=false\nFEATURE_FLOW_TRIGGERS=false\nFEATURE_SLACK_ALERTS=false\nFEATURE_ORDER_EXCLUSIONS=false\nFEATURE_LOCATION_EXCLUSIONS=false\nFEATURE_ALTERNATE_SUPPLIERS=false\nFEATURE_SAVED_VIEWS=false\nFEATURE_SIZE_RUNS=false\nBILLING_GROWTH_OFFERED=false\n

listing-screenshots: ## App Store screenshots (1600x900) of the v1 app into docs/listing/screenshots (restores backend/.env and the plan)
	@env='$(CURDIR)/backend/.env'; cp "$$env" "$$env.listing-backup"; \
	plan=$$($(DC) exec -T app php artisan tinker --execute='echo App\Models\Shop::first()->plan->value;' | tail -1); \
	trap 'mv "$$env.listing-backup" "$$env"; $(DC) exec -T app php artisan dev:set-plan "$$plan" >/dev/null' EXIT; \
	printf '$(V1_OFF)' >> "$$env"; \
	$(DC) exec -T app php artisan dev:set-plan starter >/dev/null; \
	(cd frontend && LISTING_SCREENSHOTS=1 npx playwright test e2e/listing-screenshots.spec.ts)

feature-screenshots: ## Screenshot of every feature that is on in v1 (Starter) into docs/screen-feature (restores backend/.env and the plan)
	@env='$(CURDIR)/backend/.env'; cp "$$env" "$$env.features-backup"; \
	plan=$$($(DC) exec -T app php artisan tinker --execute='echo App\Models\Shop::first()->plan->value;' | tail -1); \
	trap 'mv "$$env.features-backup" "$$env"; $(DC) exec -T app php artisan dev:set-plan "$$plan" >/dev/null' EXIT; \
	printf '$(V1_OFF)' >> "$$env"; \
	$(DC) exec -T app php artisan dev:set-plan starter >/dev/null; \
	(cd frontend && FEATURE_SCREENSHOTS=1 npx playwright test e2e/feature-screenshots.spec.ts)

listing-video: ## Draft App Store walkthrough video (WebM) of the v1 app into docs/listing (restores backend/.env and the plan)
	@env='$(CURDIR)/backend/.env'; cp "$$env" "$$env.video-backup"; \
	plan=$$($(DC) exec -T app php artisan tinker --execute='echo App\Models\Shop::first()->plan->value;' | tail -1); \
	trap 'mv "$$env.video-backup" "$$env"; $(DC) exec -T app php artisan dev:set-plan "$$plan" >/dev/null' EXIT; \
	printf '$(V1_OFF)' >> "$$env"; \
	$(DC) exec -T app php artisan dev:set-plan starter >/dev/null; \
	(cd frontend && LISTING_VIDEO=1 npx playwright test e2e/listing-walkthrough.spec.ts)

e2e-features-off: ## E2E check of the app with optional features switched off (restores backend/.env afterwards)
	@env='$(CURDIR)/backend/.env'; cp "$$env" "$$env.e2e-backup"; \
	trap 'mv "$$env.e2e-backup" "$$env"' EXIT; \
	printf '\nFEATURE_WHAT_IF=false\nFEATURE_TRANSFERS=false\nFEATURE_FLOW_TRIGGERS=false\nFEATURE_ABC=false\n' >> "$$env"; \
	(cd frontend && E2E_FEATURES_OFF=1 npx playwright test e2e/features-off.spec.ts)

extensions: ## Install admin extension deps, copy the app's forecast translations into them, type-check
	npm install
	npm run extensions:locales
	npm run extensions:typecheck

logs: ## Tail logs (make logs s=horizon for one service)
	$(DC) logs -f --tail=100 $(s)

artisan: ## Run artisan: make artisan c="route:list"
	$(DC) exec app php artisan $(c)

composer: ## Run composer: make composer c="require foo/bar"
	$(DC) exec app composer $(c)

npm: ## Run npm in frontend/ (node container): make npm c="install x"
	$(DC) exec node npm $(c)

typecheck: ## Type-check the frontend
	$(DC) exec node npx tsc --noEmit

website: ## Open the marketing website (dev server of website/, started by make up)
	@echo "http://localhost:$${FORWARD_WEBSITE_PORT:-4321}"

website-build: ## Check translations + types and build the static website into website/dist
	$(DC) run --rm --no-deps website sh -c "npm install --no-audit --no-fund && npm run check && npm run build"

admin-setup: ## First run of the owner reports (admin/): env file with the app's DB credentials, deps, key, migrate
	@test -f admin/.env || { cp admin/.env.example admin/.env; \
		for k in DATABASE USERNAME PASSWORD; do v=$$(grep -E "^DB_$$k=" backend/.env | cut -d= -f2-); \
		sed -i.bak "s|^APPDB_$$k=.*|APPDB_$$k=$$v|" admin/.env; done; rm -f admin/.env.bak; }
	$(DC) run --rm --no-deps admin composer install
	@grep -qE '^APP_KEY=.+' admin/.env || $(DC) run --rm --no-deps admin php artisan key:generate --ansi
	$(DC) up -d admin
	@echo "Next: make admin-user EMAIL=you@example.com, then open http://localhost:$${FORWARD_ADMIN_PORT:-8090}"

admin-user: ## Create the owner account of the reports or reset its password: make admin-user EMAIL=you@example.com
	$(DC) exec admin php artisan admin:user $(EMAIL)

admin-test: ## Run the tests of the owner reports
	$(DC) run --rm --no-deps admin php artisan test

tunnel: ## Start the HTTPS tunnel, print its URL and write it into backend/.env, frontend/.env, shopify.app.toml
	@if grep -qE '^TUNNEL_TOKEN=.+' backend/.env; then \
		$(DC) --profile tunnel-named up -d tunnel-named; \
		echo "Named tunnel running. APP_URL in backend/.env and frontend/.env must be its hostname."; \
	else \
		$(DC) --profile tunnel up -d tunnel; \
		$(MAKE) --no-print-directory app-url; \
	fi

tunnel-url: ## Print the current quick-tunnel URL
	@for i in $$(seq 1 30); do \
		url=$$($(DC) --profile tunnel logs tunnel 2>/dev/null | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -1); \
		if [ -n "$$url" ]; then echo $$url; exit 0; fi; sleep 1; \
	done; echo "Tunnel URL not found (check: make logs s=tunnel)" >&2; exit 1

app-url: ## Write the tunnel URL into both env files and shopify.app.toml, then restart workers
	@url=$$($(MAKE) --no-print-directory tunnel-url) && ./docker/scripts/set-app-url.sh "$$url" && $(DC) restart horizon scheduler

webhook: ## Send a signed test webhook locally: make webhook t=app/uninstalled [shop=x.myshopify.com]
	@./docker/scripts/send-webhook.sh "$(t)" "$(or $(shop),demo.myshopify.com)"

tunnel-down: ## Stop the tunnel
	$(DC) --profile tunnel --profile tunnel-named stop tunnel tunnel-named

prod-build: ## Build production images
	$(DC_PROD) build

prod-up: ## Start production stack
	$(DC_PROD) up -d

prod-down: ## Stop production stack
	$(DC_PROD) down

prod-migrate: ## Run migrations in production by hand (CI/CD deploys run additive ones itself)
	$(DC_PROD) run --rm app php artisan migrate --force

prod-logs: ## Tail production logs
	$(DC_PROD) logs -f --tail=100 $(s)
