@extends('layouts.app')
@section('title', 'Shops')
@section('content')
    @php $tz = config('report.timezone'); @endphp
    <h1>Shops</h1>
    <p class="sub">{{ number_format($counts['installed']) }} installed · {{ number_format($counts['uninstalled']) }} uninstalled</p>

    <form class="filters" method="get">
        <label>Search <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Name or domain"></label>
        <label>Status
            <select name="status">
                <option value="">All</option>
                @foreach (['installed' => 'Installed', 'uninstalled' => 'Uninstalled', 'deleted' => 'Uninstalled, data deleted'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>Plan
            <select name="plan">
                <option value="">All</option>
                @foreach (['free', 'starter', 'growth'] as $plan)<option value="{{ $plan }}" @selected($filters['plan'] === $plan)>{{ ucfirst($plan) }}</option>@endforeach
            </select>
        </label>
        <label>Uses feature
            <select name="feature">
                <option value="">Any</option>
                @foreach ($features as $key => $f)
                    @if ($f['available'])<option value="{{ $key }}" @selected($filters['feature'] === $key)>{{ $f['label'] }} ({{ count($f['shop_ids']) }})</option>@endif
                @endforeach
            </select>
        </label>
        <label>Sort
            <select name="sort">
                @foreach (['installed_at' => 'Newest install', 'uninstalled_at' => 'Latest uninstall', 'name' => 'Name', 'plan' => 'Plan'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button>Filter</button>
        <a class="btn plain" href="{{ route('shops.export', request()->query()) }}">Export CSV</a>
    </form>

    <div class="card flush">
        <table>
            <tr><th>Shop</th><th>Status</th><th>Plan</th><th>Installed</th><th>Uninstalled</th><th class="num">Days</th></tr>
            @forelse ($shops as $shop)
                <tr>
                    <td><a href="{{ route('shops.show', $shop) }}">{{ $shop->label() }}</a>@if ($shop->name && $shop->domain)<br><span class="muted">{{ $shop->domain }}</span>@endif</td>
                    <td><span class="badge {{ ['installed' => 'good', 'uninstalled' => 'bad', 'deleted' => 'off'][$shop->status()] }}">{{ ucfirst($shop->status()) }}</span></td>
                    <td>{{ ucfirst($shop->isInstalled() ? $shop->plan : ($shop->plan_at_uninstall ?? 'free')) }}{{ $shop->isInstalled() && $shop->plan_interval ? ' · '.$shop->plan_interval : '' }}</td>
                    <td>{{ $shop->installed_at?->setTimezone($tz)->format('M j, Y') ?? '—' }}</td>
                    <td>{{ $shop->uninstalled_at?->setTimezone($tz)->format('M j, Y') ?? '—' }}</td>
                    <td class="num">{{ $shop->daysInstalled() ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No shop matches.</td></tr>
            @endforelse
        </table>
        @if ($shops->hasPages())
            <div class="pager">
                @if ($shops->previousPageUrl())<a href="{{ $shops->previousPageUrl() }}">← Newer</a>@endif
                <span class="muted">Page {{ $shops->currentPage() }} of {{ $shops->lastPage() }} · {{ number_format($shops->total()) }} shops</span>
                @if ($shops->nextPageUrl())<a href="{{ $shops->nextPageUrl() }}">Older →</a>@endif
            </div>
        @endif
    </div>
@endsection
