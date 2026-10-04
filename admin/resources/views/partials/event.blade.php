@php
    $labels = ['installed' => ['Installed', 'good'], 'reinstalled' => ['Reinstalled', 'good'], 'uninstalled' => ['Uninstalled', 'bad'], 'plan_changed' => ['Plan changed', ''], 'redacted' => ['Data deleted', 'off']];
    [$label, $tone] = $labels[$event->type] ?? [$event->type, ''];
@endphp
<span class="badge {{ $tone }}">{{ $label }}</span>
@if ($event->type === 'plan_changed')
    {{ ucfirst($event->plan_from) }} → {{ ucfirst($event->plan_to) }}{{ $event->interval ? ' ('.$event->interval.')' : '' }}
@elseif ($event->type === 'uninstalled' && $event->plan_from && $event->plan_from !== 'free')
    was on {{ ucfirst($event->plan_from) }}
@elseif (in_array($event->type, ['installed', 'reinstalled']) && $event->plan_to && $event->plan_to !== 'free')
    on {{ ucfirst($event->plan_to) }}
@endif
@if ((float) $event->mrr_change != 0)
    <span class="{{ $event->mrr_change > 0 ? 'good' : 'bad' }}">{{ $event->mrr_change > 0 ? '+' : '−' }}${{ number_format(abs($event->mrr_change), 2) }} MRR</span>
@endif
