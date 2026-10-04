@extends('layouts.app')
@section('title', 'Feature usage')
@section('content')
    <h1>Feature usage</h1>
    <p class="sub">How many of the {{ number_format($installed) }} installed shops use each feature, judged by the data they stored. Click a number for the shops.</p>

    <div class="kpis">
        @foreach (['none' => 'Use no feature beyond the forecast', '1–2' => 'Use 1–2 features', '3–5' => 'Use 3–5 features', '6+' => 'Use 6 or more'] as $bucket => $label)
            <div class="kpi"><div class="n">{{ $depth[$bucket] ?? 0 }}</div><div class="l">{{ $label }}</div></div>
        @endforeach
    </div>

    @foreach ($groups as $group => $rows)
        <div class="card flush">
            <h2>{{ $group }}</h2>
            <table>
                <tr><th>Feature</th><th>Plan</th><th class="num">Shops</th><th style="width:22%">Share of installed</th><th class="num">Free</th><th class="num">Starter</th><th class="num">Growth</th><th class="num">vs {{ $before_date?->format('M j') ?? '30 days ago' }}</th></tr>
                @foreach ($rows->sortByDesc('count') as $f)
                    <tr>
                        <td>{{ $f['label'] }}</td>
                        <td><span class="badge {{ $f['plan'] === 'free' ? 'off' : '' }}">{{ ucfirst($f['plan']) }}</span></td>
                        @if (! $f['available'])
                            <td colspan="6" class="muted">Not in the deployed version of the app yet</td>
                        @else
                            <td class="num"><a href="{{ route('shops.index', ['status' => 'installed', 'feature' => $f['key']]) }}">{{ $f['count'] }}</a></td>
                            <td><div style="display:flex;gap:8px;align-items:center"><div class="bar" style="flex:1"><i style="width:{{ round($f['share'] * 100) }}%"></i></div><span class="muted" style="min-width:42px;text-align:right">{{ number_format($f['share'] * 100, 0) }}%</span></div></td>
                            <td class="num">{{ $f['by_plan']['free'] ?? 0 }}</td>
                            <td class="num">{{ $f['by_plan']['starter'] ?? 0 }}</td>
                            <td class="num">{{ $f['by_plan']['growth'] ?? 0 }}</td>
                            <td class="num">@if ($f['before'] === null)<span class="muted">—</span>@else @php $d = $f['count'] - $f['before']; @endphp<span class="{{ $d > 0 ? 'good' : ($d < 0 ? 'bad' : 'muted') }}">{{ $d > 0 ? '+' : '' }}{{ $d }}</span>@endif</td>
                        @endif
                    </tr>
                @endforeach
            </table>
        </div>
    @endforeach
    <p class="muted">Features that store nothing (what-if, purchase plan, exports, insights pages) can't be counted from data. See "Usage events" in admin/README.md.</p>
@endsection
