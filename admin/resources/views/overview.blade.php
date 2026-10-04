@extends('layouts.app')
@section('title', 'Overview')
@section('content')
    @php
        $pct = fn (?float $ratio) => $ratio === null ? '—' : number_format($ratio * 100, 1).'%';
        $max = max(1, collect($weeks)->max(fn ($w) => max($w['installs'], $w['uninstalls'])));
        $tz = config('report.timezone');
    @endphp
    <div style="display:flex;justify-content:space-between;align-items:start;gap:12px;flex-wrap:wrap">
        <div>
            <h1>Overview</h1>
            <p class="sub">{{ $now->format('D, M j Y · H:i') }} ({{ $tz }})</p>
        </div>
        <form method="post" action="{{ route('sync') }}">@csrf<button class="plain">Refresh now</button></form>
    </div>

    <div class="kpis">
        <div class="kpi"><div class="n">{{ number_format($totals['installed']) }}</div><div class="l">Shops with the app installed</div><div class="d">{{ number_format($totals['ever']) }} installed it at some point</div></div>
        <div class="kpi"><div class="n">{{ number_format($totals['uninstalled']) }}</div><div class="l">Shops that uninstalled</div><div class="d">{{ $pct($totals['churn_rate']) }} of all installs</div></div>
        <div class="kpi"><div class="n">{{ number_format($totals['paying']) }}</div><div class="l">On a paid plan</div><div class="d">{{ $pct($totals['paid_rate']) }} of installed · {{ $totals['trialing'] }} in trial</div></div>
        <div class="kpi"><div class="n">${{ number_format($totals['mrr'], 2) }}</div><div class="l">MRR (estimate)</div><div class="d">${{ number_format($totals['arr'], 0) }} a year</div></div>
        <div class="kpi"><div class="n">{{ $totals['active'] === null ? '—' : number_format($totals['active']) }}</div><div class="l">Active shops</div><div class="d">forecast in the last {{ config('report.active_days') }} days</div></div>
        <div class="kpi"><div class="n">{{ number_format($totals['onboarded']) }}</div><div class="l">Finished onboarding</div><div class="d">of {{ number_format($totals['installed']) }} installed</div></div>
    </div>

    <div class="card">
        <h2>Installs and uninstalls per week</h2>
        <div class="legend"><span><i style="background:var(--accent)"></i>Installs (incl. reinstalls)</span><span><i style="background:var(--bad);opacity:.75"></i>Uninstalls</span></div>
        <div class="chart" role="img" aria-label="Installs and uninstalls per week, last {{ count($weeks) }} weeks">
            @foreach ($weeks as $w)
                <div class="w" title="Week of {{ \Illuminate\Support\Carbon::parse($w['start'])->format('M j') }}: {{ $w['installs'] }} installs, {{ $w['uninstalls'] }} uninstalls">
                    <i class="in" style="height:{{ round($w['installs'] / $max * 100) }}%"></i>
                    <i class="out" style="height:{{ round($w['uninstalls'] / $max * 100) }}%"></i>
                </div>
            @endforeach
        </div>
        <div class="axis">@foreach ($weeks as $w)<span>{{ \Illuminate\Support\Carbon::parse($w['start'])->format('M j') }}</span>@endforeach</div>
    </div>

    <div class="card flush">
        <table>
            <tr><th>Week of</th>@foreach (array_slice($weeks, -8) as $w)<th class="num">{{ \Illuminate\Support\Carbon::parse($w['start'])->format('M j') }}</th>@endforeach</tr>
            <tr><td>Installs</td>@foreach (array_slice($weeks, -8) as $w)<td class="num">{{ $w['installs'] }}</td>@endforeach</tr>
            <tr><td>Uninstalls</td>@foreach (array_slice($weeks, -8) as $w)<td class="num">{{ $w['uninstalls'] }}</td>@endforeach</tr>
            <tr><td>Net</td>@foreach (array_slice($weeks, -8) as $w)<td class="num {{ $w['net'] < 0 ? 'bad' : ($w['net'] > 0 ? 'good' : '') }}">{{ $w['net'] > 0 ? '+' : '' }}{{ $w['net'] }}</td>@endforeach</tr>
        </table>
    </div>

    <div class="cols">
        <div class="card flush">
            <h2>Plans (installed shops)</h2>
            <table>
                <tr><th>Plan</th><th class="num">Shops</th><th class="num">Monthly</th><th class="num">Annual</th><th class="num">MRR</th></tr>
                @foreach ($plans as $plan => $p)
                    <tr>
                        <td><a href="{{ route('shops.index', ['status' => 'installed', 'plan' => $plan]) }}">{{ ucfirst($plan) }}</a></td>
                        <td class="num">{{ $p['shops'] }}</td>
                        <td class="num">{{ $plan === 'free' ? '—' : $p['monthly'] }}</td>
                        <td class="num">{{ $plan === 'free' ? '—' : $p['annual'] }}</td>
                        <td class="num">{{ $plan === 'free' ? '—' : '$'.number_format($p['mrr'], 2) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
        <div class="card">
            <h2>Shops that left</h2>
            @if ($totals['uninstalled'] === 0)
                <p class="muted">No shop has uninstalled.</p>
            @else
                <dl class="facts">
                    <dt>Median stay</dt><dd>{{ $stays['median_days'] === null ? '—' : $stays['median_days'].' days' }}</dd>
                    <dt>Left the same day</dt><dd>{{ $stays['same_day'] }}</dd>
                    <dt>Within the first week</dt><dd>{{ $stays['first_week'] }}</dd>
                    <dt>Within the first month</dt><dd>{{ $stays['first_month'] }}</dd>
                    <dt>After a month or more</dt><dd>{{ $stays['later'] }}</dd>
                    <dt>Were on a paid plan</dt><dd>{{ $stays['paid_before'] }}</dd>
                    <dt>Data already deleted</dt><dd>{{ $totals['deleted'] }} <span class="muted">(48 hours after the uninstall)</span></dd>
                </dl>
            @endif
        </div>
    </div>

    <div class="card flush">
        <h2>Latest activity</h2>
        <table>
            @forelse ($recent as $event)
                <tr>
                    <td style="white-space:nowrap" class="muted">{{ $event->occurred_at->setTimezone($tz)->format('M j, H:i') }}</td>
                    <td><a href="{{ route('shops.show', $event->shop) }}">{{ $event->shop->label() }}</a></td>
                    <td>@include('partials.event', ['event' => $event])</td>
                </tr>
            @empty
                <tr><td class="muted">Nothing yet. The first refresh records every shop in the app.</td></tr>
            @endforelse
        </table>
    </div>
    <p class="muted">MRR uses the list prices; shops keep the price they subscribed at, and trials have not paid yet.</p>
@endsection
