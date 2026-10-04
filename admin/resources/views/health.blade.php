@extends('layouts.app')
@section('title', 'Health')
@section('content')
    @php
        $tz = config('report.timezone');
        $at = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value, 'UTC')->setTimezone($tz)->format('M j, H:i') : 'never';
        $link = fn (array $s) => isset($records[$s['id']]) ? route('shops.show', $records[$s['id']]) : null;
    @endphp
    <h1>Health</h1>
    <p class="sub">What needs attention in the running app. Last 7 days where a period applies.</p>

    <div class="kpis">
        <div class="kpi"><div class="n {{ count($failed_syncs ?? []) ? 'bad' : '' }}">{{ $failed_syncs === null ? '—' : count($failed_syncs) }}</div><div class="l">Shops whose last sync failed</div></div>
        <div class="kpi"><div class="n {{ count($stale_forecasts ?? []) ? 'bad' : '' }}">{{ $stale_forecasts === null ? '—' : count($stale_forecasts) }}</div><div class="l">Forecasts older than 36 hours</div></div>
        <div class="kpi"><div class="n">{{ $never_synced ?? '—' }}</div><div class="l">Installed 2+ hours ago, never synced</div></div>
        <div class="kpi"><div class="n {{ ($failed_jobs['week'] ?? 0) ? 'bad' : '' }}">{{ $failed_jobs['week'] ?? '—' }}</div><div class="l">Failed jobs this week</div><div class="d">{{ $failed_jobs['total'] ?? 0 }} in the table</div></div>
    </div>

    <div class="cols">
        <div class="card flush">
            <h2>Failed syncs</h2>
            <table>
                @forelse ($failed_syncs ?? [] as $s)
                    <tr><td>@if ($link($s))<a href="{{ $link($s) }}">{{ $s['label'] }}</a>@else{{ $s['label'] }}@endif</td><td>{{ $s['error'] ?? 'unknown' }}</td><td class="muted">last synced {{ $at($s['last_synced_at']) }}</td></tr>
                @empty
                    <tr><td class="muted">None.</td></tr>
                @endforelse
            </table>
        </div>
        <div class="card flush">
            <h2>Stale forecasts</h2>
            <table>
                @forelse ($stale_forecasts ?? [] as $s)
                    <tr><td>@if ($link($s))<a href="{{ $link($s) }}">{{ $s['label'] }}</a>@else{{ $s['label'] }}@endif</td><td class="muted">computed {{ $at($s['forecasted_at']) }}</td></tr>
                @empty
                    <tr><td class="muted">None.</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    @if ($web_vitals !== null)
        <div class="card flush">
            <h2>App speed in the Shopify admin (75th percentile, 28 days)</h2>
            <table>
                <tr><th>Metric</th><th class="num">Measured</th><th class="num">Built for Shopify limit</th><th class="num">Measurements</th><th></th></tr>
                @foreach ($web_vitals as $v)
                    <tr>
                        <td>{{ $v['metric'] }}</td>
                        <td class="num {{ $v['ok'] === false ? 'bad' : '' }}">{{ $v['p75'] === null ? '—' : ($v['metric'] === 'CLS' ? number_format($v['p75'], 3) : number_format($v['p75']).' ms') }}</td>
                        <td class="num">{{ $v['metric'] === 'CLS' ? $v['limit'] : number_format($v['limit']).' ms' }}</td>
                        <td class="num">{{ number_format($v['samples']) }}</td>
                        <td>
                            @if ($v['p75'] === null)<span class="muted">No data yet</span>
                            @elseif (! $v['enough'])<span class="badge warn">Under 100 measurements: not judged yet</span>
                            @elseif ($v['ok'])<span class="badge good">Within the limit</span>
                            @else<span class="badge bad">Over the limit</span>@endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    <div class="cols">
        <div class="card flush">
            <h2>Sync runs</h2>
            <table>
                @forelse ($sync_runs ?? [] as $status => $n)
                    <tr><td>{{ ucfirst($status) }}</td><td class="num">{{ number_format($n) }}</td></tr>
                @empty
                    <tr><td class="muted">No sync ran.</td></tr>
                @endforelse
            </table>
        </div>
        <div class="card flush">
            <h2>Emails</h2>
            <table>
                <tr><th>Type</th><th class="num">Sent</th><th class="num">Failed</th></tr>
                @forelse ($emails ?? [] as $e)
                    <tr><td>{{ $e['type'] }}</td><td class="num">{{ number_format($e['sent']) }}</td><td class="num {{ $e['failed'] ? 'bad' : '' }}">{{ number_format($e['failed']) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="muted">No email went out.</td></tr>
                @endforelse
            </table>
        </div>
    </div>
@endsection
