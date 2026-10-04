@extends('layouts.app')
@section('title', $shop->label())
@section('content')
    @php
        $tz = config('report.timezone');
        $at = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value, 'UTC')->setTimezone($tz)->format('M j, Y H:i') : '—';
    @endphp
    <p><a href="{{ route('shops.index') }}">← Shops</a></p>
    <h1>{{ $shop->label() }} <span class="badge {{ ['installed' => 'good', 'uninstalled' => 'bad', 'deleted' => 'off'][$shop->status()] }}">{{ ucfirst($shop->status()) }}</span></h1>
    <p class="sub">{{ $shop->domain ?? 'The app deleted this shop\'s data after the uninstall; only dates and plans are kept.' }}</p>

    <div class="cols">
        <div class="card">
            <h2>Account</h2>
            <dl class="facts">
                <dt>Plan</dt><dd>{{ ucfirst($shop->plan) }}{{ $shop->plan_interval ? ' · '.$shop->plan_interval : '' }}{{ $mrr > 0 ? ' · $'.number_format($mrr, 2).' MRR' : '' }}</dd>
                @if ($shop->plan_at_uninstall)<dt>Plan when it left</dt><dd>{{ ucfirst($shop->plan_at_uninstall) }}</dd>@endif
                <dt>First installed</dt><dd>{{ $at($shop->first_installed_at) }}</dd>
                <dt>Latest install</dt><dd>{{ $at($shop->installed_at) }}</dd>
                <dt>Uninstalled</dt><dd>{{ $at($shop->uninstalled_at) }}</dd>
                <dt>Days installed</dt><dd>{{ $shop->daysInstalled() ?? '—' }}</dd>
                <dt>Onboarding finished</dt><dd>{{ $at($shop->onboarded_at) }}</dd>
                <dt>Trial started</dt><dd>{{ $at($shop->trial_started_at) }}</dd>
            </dl>
        </div>
        @if ($live)
            <div class="card">
                <h2>In the app now</h2>
                <dl class="facts">
                    <dt>Sync</dt><dd>{{ $live['sync_status'] ?? '—' }}{{ $live['sync_error'] ? ' ('.$live['sync_error'].')' : '' }}</dd>
                    <dt>Last synced</dt><dd>{{ $at($live['last_synced_at']) }}</dd>
                    <dt>Forecasts computed</dt><dd>{{ $at($live['forecasted_at']) }}</dd>
                    <dt>Subscription</dt><dd>{{ $live['subscription_status'] ?? '—' }}{{ $live['plan_renews_at'] ? ', renews '.$at($live['plan_renews_at']) : '' }}</dd>
                    <dt>Timezone / language</dt><dd>{{ $live['timezone'] ?? '—' }} / {{ $live['locale'] ?? 'Shopify admin language' }}</dd>
                    @foreach ($counts as $label => $n)<dt>{{ $label }}</dt><dd>{{ number_format($n) }}</dd>@endforeach
                </dl>
            </div>
        @endif
    </div>

    @if ($features !== [])
        <div class="card">
            <h2>Features used</h2>
            <div class="cols">
                @foreach ($features as $group => $items)
                    <div>
                        <p class="muted" style="margin:0 0 6px">{{ $group }}</p>
                        <ul class="plain">
                            @foreach ($items as $f)
                                <li><span class="badge {{ $f['used'] ? 'good' : 'off' }}">{{ $f['used'] ? 'Uses' : 'No' }}</span> {{ $f['label'] }}@if ($f['plan'] !== 'free') <span class="muted">({{ ucfirst($f['plan']) }})</span>@endif</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card flush">
        <h2>History</h2>
        <table>
            @foreach ($shop->events as $event)
                <tr><td style="white-space:nowrap" class="muted">{{ $event->occurred_at->setTimezone($tz)->format('M j, Y H:i') }}</td><td>@include('partials.event', ['event' => $event])</td></tr>
            @endforeach
        </table>
    </div>
@endsection
