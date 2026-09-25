<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ $appName }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #303030; background: #f1f1f1; margin: 0; }
        main { max-width: 760px; margin: 40px auto; padding: 32px; background: #fff; border-radius: 12px; line-height: 1.6; }
        h1 { font-size: 1.6rem; margin-top: 0; }
        h2 { font-size: 1.15rem; margin-top: 1.8rem; }
        a { color: #005bd3; }
        footer { max-width: 760px; margin: 0 auto 40px; font-size: .85rem; color: #616161; text-align: center; }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
<footer>
    <a href="{{ route('privacy') }}">Privacy policy</a> · <a href="{{ route('support') }}">Support</a>
</footer>
</body>
</html>
