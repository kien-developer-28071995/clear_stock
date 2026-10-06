#!/usr/bin/env bash
# Usage: docker/scripts/set-app-url.sh https://xyz.trycloudflare.com
# Updates APP_URL in app/backend/.env and app/frontend/.env, FRONTEND_URL and the frontend's copy of the
# client id (VITE_SHOPIFY_API_KEY), application_url / redirect_urls
# in shopify.app.toml and the Flow lifecycle callback URL.
# Afterwards push the toml to Shopify:  npx @shopify/cli@latest app deploy
set -euo pipefail
url="${1:?usage: $0 https://your-tunnel-host}"
url="${url%/}"
cd "$(dirname "$0")/../.."

for env in app/backend/.env app/frontend/.env; do
  [ -f "$env" ] || cp "$env.example" "$env"
  if grep -q '^APP_URL=' "$env"; then
    sed -i.bak "s|^APP_URL=.*|APP_URL=${url}|" "$env" && rm -f "$env.bak"
  else
    echo "APP_URL=${url}" >> "$env"
  fi
done

if [ -f shopify.app.toml ]; then
  sed -i.bak -E \
    -e "s|^application_url = \".*\"|application_url = \"${url}\"|" \
    -e "s|^redirect_urls = \[.*\]|redirect_urls = [ \"${url}/auth/callback\" ]|" \
    shopify.app.toml && rm -f shopify.app.toml.bak
fi

# Flow lifecycle callback (absolute URL in the extension config).
lifecycle=extensions/flow-lifecycle/shopify.extension.toml
if [ -f "$lifecycle" ]; then
  sed -i.bak -E "s|^url = \".*\"|url = \"${url}/flow/lifecycle\"|" "$lifecycle" && rm -f "$lifecycle.bak"
fi

# The frontend is a separate app; in dev the tunnel hostname fronts both (docker/nginx/dev.conf).
set_env() { # file key value
  if grep -q "^$2=" "$1"; then sed -i.bak "s|^$2=.*|$2=$3|" "$1" && rm -f "$1.bak"; else echo "$2=$3" >> "$1"; fi
}
set_env app/backend/.env FRONTEND_URL "${url}"
key=$(grep -E '^SHOPIFY_API_KEY=' app/backend/.env | cut -d= -f2- || true)
[ -z "$key" ] || set_env app/frontend/.env VITE_SHOPIFY_API_KEY "$key"

echo "APP_URL, FRONTEND_URL -> ${url} (app/backend/.env, app/frontend/.env)"
echo "shopify.app.toml updated. Push it with: npx @shopify/cli@latest app deploy"
