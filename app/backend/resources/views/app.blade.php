<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $appName }}</title>
    {{-- App Bridge + Polaris web components from Shopify's CDN (must load before app code). --}}
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    {{-- App Bridge renders the save bar in the admin chrome; its source element never shows in the iframe. --}}
    <style>ui-save-bar { display: none; }</style>
    <script src="https://cdn.shopify.com/shopifycloud/polaris-1.js"></script>
    <script>window.__APP_CONFIG__ = @json(['appName' => $appName]);</script>
    @viteReactRefresh
    @vite('src/main.tsx')
</head>
<body>
    <div id="root"></div>
</body>
</html>
