# Developer shortcuts. Run `make help` to list them.
SHELL := /bin/bash
DC      := docker compose
DC_PROD := docker compose -f docker-compose.prod.yml
export UID := $(shell id -u)
export GID := $(shell id -g)

.DEFAULT_GOAL := help
.PHONY: help setup up down restart build shell migrate fresh test e2e logs tunnel tunnel-url tunnel-down \
        app-url webhook artisan composer npm typecheck prod-build prod-up prod-down prod-migrate prod-logs

help: ## List available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

setup: ## First run: env files, build images, install deps, app key, migrate
	@test -f backend/.env || cp backend/.env.example backend/.env
	@test -f frontend/.env || cp frontend/.env.example frontend/.env
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

e2e: ## End-to-end tests in Chromium on every plan (needs make up + a synced dev store)
	cd frontend && npx playwright test

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

prod-migrate: ## Run migrations in production (explicit, never automatic)
	$(DC_PROD) run --rm app php artisan migrate --force

prod-logs: ## Tail production logs
	$(DC_PROD) logs -f --tail=100 $(s)
