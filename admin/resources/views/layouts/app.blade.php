<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Reports') · {{ config('report.app_name') }} Admin</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
</head>
<body>
@auth
    <header class="top">
        <div class="in">
            <strong>{{ config('report.app_name') }} Admin</strong>
            <nav>
                @foreach (['overview' => 'Overview', 'shops.index' => 'Shops', 'features' => 'Feature usage', 'health' => 'Health'] as $route => $label)
                    <a href="{{ route($route) }}" @class(['on' => request()->routeIs(str_replace('.index', '.*', $route))])>{{ $label }}</a>
                @endforeach
            </nav>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="plain">Sign out</button></form>
        </div>
    </header>
@endauth
<main>
    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif
    @yield('content')
</main>
</body>
</html>
