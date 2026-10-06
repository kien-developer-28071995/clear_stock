<x-mail::message>
# {{ $totalCount === 1 ? '1 product needs' : $totalCount.' products need' }} reordering

{{ $newCount === $totalCount ? 'Here is what to order now for' : $newCount.' new since your last summary for' }} **{{ $shop->name ?? $shop->domain }}**.

<x-mail::table>
| Product | In stock | Runs out | Order |
|:--------|--------:|:---------|------:|
@foreach ($items as $item)
| {{ $item['new'] ? '🆕 ' : '' }}**{{ $item['name'] }}**{{ $item['sku'] ? ' · '.$item['sku'] : '' }}<br><small>{{ $item['why'] }}</small> | {{ $item['stock'] }} | {{ $item['out_of_stock'] ? 'Out of stock' : $item['stockout_date'] }}{{ ($item['low_cover_days'] ?? null) !== null ? ' ('.$item['low_cover_days'].' days left)' : '' }} | **{{ $item['order_qty'] }}**{{ $item['order_by'] ? ' by '.$item['order_by'] : '' }} |
@endforeach
</x-mail::table>

@if ($more > 0)
…and {{ $more }} more in the app.
@endif

<x-mail::button :url="$appUrl">
Open {{ $appName }}
</x-mail::button>

<small>You get one {{ $frequency->value === 'weekly' ? 'weekly' : 'daily' }} summary, only when something new needs attention. [Change or turn off alerts]({{ $settingsUrl }}).</small>
</x-mail::message>
