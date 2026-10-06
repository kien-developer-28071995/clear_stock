#!/usr/bin/env bash
# Send a webhook signed with SHOPIFY_API_SECRET (from app/backend/.env) to the local dev stack.
# Usage: docker/scripts/send-webhook.sh <topic> [shop-domain] [url]
#   docker/scripts/send-webhook.sh app/uninstalled my-store.myshopify.com
set -euo pipefail
cd "$(dirname "$0")/../.."
topic="${1:?usage: $0 <topic> [shop-domain] [url]}"
shop="${2:-demo.myshopify.com}"
url="${3:-http://localhost:${APP_PORT:-8080}/webhooks}"
secret=$(grep -E '^SHOPIFY_API_SECRET=' app/backend/.env | cut -d= -f2- | tr -d '"')
[ -n "$secret" ] || { echo "SHOPIFY_API_SECRET is empty in app/backend/.env" >&2; exit 1; }

case "$topic" in
  customers/data_request) body="{\"shop_domain\":\"$shop\",\"customer\":{\"id\":1,\"email\":\"test@example.com\"},\"orders_requested\":[1],\"data_request\":{\"id\":1}}" ;;
  customers/redact)       body="{\"shop_domain\":\"$shop\",\"customer\":{\"id\":1,\"email\":\"test@example.com\"},\"orders_to_redact\":[1]}" ;;
  app/scopes_update)      body="{\"previous\":[],\"current\":[\"read_products\",\"read_inventory\",\"read_locations\",\"read_orders\"]}" ;;
  *)                      body="{\"shop_domain\":\"$shop\"}" ;;
esac

hmac=$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$secret" -binary | base64)
curl -s -o /dev/null -w "$topic ($shop) -> HTTP %{http_code}\n" -X POST "$url" \
  -H "Content-Type: application/json" \
  -H "X-Shopify-Topic: $topic" \
  -H "X-Shopify-Shop-Domain: $shop" \
  -H "X-Shopify-Webhook-Id: local-$(date +%s)-$RANDOM" \
  -H "X-Shopify-Hmac-Sha256: ${FORGE_HMAC:-$hmac}" \
  -d "$body"
