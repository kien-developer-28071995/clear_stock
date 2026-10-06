#!/usr/bin/env bash
# Checks the web server configs that sit in front of the app, with the real servers in Docker:
#   - docker/nginx/*.conf parse (nginx -t);
#   - deploy/Caddyfile parses, and its frontend block behaves: the page for every client-side
#     route, and the iframe protection header per shop (required by Shopify for embedded apps).
# Run in CI (job web-config) and by hand: bash deploy/check-web-config.sh
set -euo pipefail
cd "$(dirname "$0")/.."
work=$(mktemp -d)
name="cs-caddy-check-$$"
trap 'docker rm -f "$name" >/dev/null 2>&1 || true; rm -rf "$work"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

for conf in docker/nginx/dev.conf docker/nginx/prod.conf; do
  docker run --rm -v "$PWD/$conf:/etc/nginx/conf.d/default.conf:ro" nginx:1.27-alpine nginx -t >/dev/null 2>"$work/nginx.log" \
    || { cat "$work/nginx.log" >&2; fail "$conf does not parse"; }
  echo "ok   $conf parses"
done
# Vite keeps dependencies under /node_modules/.vite: the dot-file rule must not come before them.
awk '/location ~ \^\/\(@vite/ {vite=NR} /location ~ \/\\\.\(\?!well-known\)/ {deny=NR} END {exit !(vite && deny && vite < deny)}' docker/nginx/dev.conf \
  || fail "docker/nginx/dev.conf: the Vite module location must come before the dot-file deny rule"
echo "ok   dev proxy serves Vite's dependency files"

# The Caddyfile with ports instead of domains (no certificates in a check) and a stand-in site.
mkdir -p "$work/frontend/assets" "$work/website"
echo '<!doctype html><title>app</title>' > "$work/frontend/index.html"
echo 'console.log(1)' > "$work/frontend/assets/app.js"
sed -e 's|/opt/clear_stock/frontend|/srv/frontend|; s|/opt/clear_stock/website|/srv/website|' \
    -e 's|^app-api\.clear-stock\.techfoxify\.com.* {|:8081 {|; s|^app\.clear-stock\.techfoxify\.com {|:8082 {|; s|^www\.example\.com {|:8083 {|' deploy/Caddyfile > "$work/Caddyfile"
grep -q '^:8082 {' "$work/Caddyfile" || fail "deploy/Caddyfile has no app.clear-stock.techfoxify.com block"
docker run -d --name "$name" -p 127.0.0.1::8082 -v "$work/Caddyfile:/etc/caddy/Caddyfile:ro" -v "$work/frontend:/srv/frontend:ro" -v "$work/website:/srv/website:ro" caddy:2-alpine >/dev/null
port=$(docker port "$name" 8082/tcp | head -1 | sed 's/.*://')
for _ in $(seq 1 20); do curl -fs -o /dev/null "http://127.0.0.1:$port/" && break; sleep 0.5; done
docker logs "$name" 2>&1 | grep -qi '"level":"error"\|^Error' && { docker logs "$name" >&2; fail "Caddy reported an error"; }
echo "ok   deploy/Caddyfile parses and serves"

header() { curl -s -o /dev/null -D - "http://127.0.0.1:$port$1" | tr -d '\r' | awk -F': ' -v h="$2" 'tolower($1)==tolower(h) {print $2}'; }
status() { curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port$1"; }
expect() { [ "$2" = "$3" ] && echo "ok   $1" || fail "$1: expected '$3', got '$2'"; }
csp=Content-Security-Policy
expect "shop may frame the app"            "$(header '/?shop=demo.myshopify.com&embedded=1' $csp)" "frame-ancestors https://demo.myshopify.com https://admin.shopify.com;"
expect "deep link keeps the protection"    "$(header '/products/12?shop=demo.myshopify.com' $csp)" "frame-ancestors https://demo.myshopify.com https://admin.shopify.com;"
expect "no shop: no framing"               "$(header '/' $csp)" "frame-ancestors 'none';"
expect "foreign shop value: no framing"    "$(header '/?shop=evil.com' $csp)" "frame-ancestors 'none';"
expect "lookalike shop value: no framing"  "$(header '/?shop=demo.myshopify.com.evil.com' $csp)" "frame-ancestors 'none';"
expect "client-side route gets the page"   "$(status '/settings?shop=demo.myshopify.com')" "200"
expect "page is always re-checked"         "$(header '/?shop=demo.myshopify.com' Cache-Control)" "no-cache"
expect "assets are cached for good"        "$(header '/assets/app.js' Cache-Control)" "public, max-age=31536000, immutable"
echo "Web config checks passed."
