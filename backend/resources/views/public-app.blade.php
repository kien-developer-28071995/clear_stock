<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ $appName }}</title>
    {{-- Public pages (privacy, support): the React bundle src/public.tsx, without App Bridge. --}}
    <script>window.__PUBLIC_CONFIG__ = @json(['appName' => $appName, 'supportEmail' => $supportEmail, 'privacyUpdated' => $privacyUpdated]);</script>
    @viteReactRefresh
    @vite('src/public.tsx')
</head>
<body>
    <div id="root"></div>
    <noscript>{{ $title }} · {{ $appName }}. Please enable JavaScript, or contact {{ $supportEmail }}.</noscript>
</body>
</html>
