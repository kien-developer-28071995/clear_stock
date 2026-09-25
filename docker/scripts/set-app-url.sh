#!/usr/bin/env bash
# Usage: docker/scripts/set-app-url.sh https://xyz.trycloudflare.com
# Updates APP_URL in backend/.env and frontend/.env, and application_url / redirect_urls
# in shopify.app.toml.
# Afterwards push the toml to Shopify:  npx @shopify/cli@latest app deploy
set -euo pipefail
url="${1:?usage: $0 https://your-tunnel-host}"
url="${url%/}"
cd "$(dirname "$0")/../.."

for env in backend/.env frontend/.env; do
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

echo "APP_URL -> ${url} (backend/.env, frontend/.env)"
echo "shopify.app.toml updated. Push it with: npx @shopify/cli@latest app deploy"
