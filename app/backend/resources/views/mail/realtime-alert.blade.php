<x-mail::message>
# {{ count($items) === 1 ? ($items[0]['out_of_stock'] ? 'A product just sold out' : 'A product is running low') : count($items).' products need attention' }}

Live stock update from **{{ $shop->name ?? $shop->domain }}**.

<x-mail::table>
| Product | In stock | Order |
|:--------|--------:|------:|
@foreach ($items as $item)
| [**{{ $item['name'] }}**]({{ $productUrl($item['variant_id']) }}){{ $item['sku'] ? ' · '.$item['sku'] : '' }}@if ($item['why'])<br><small>{{ $item['why'] }}</small>@endif @if ($item['locations'])<br><small>{{ collect($item['locations'])->map(fn ($l) => $l['name'].': '.$l['available'])->implode(' · ') }}</small>@endif | {{ $item['out_of_stock'] ? 'Sold out' : $item['stock'] }} | @if ($item['order_qty'])**{{ $item['order_qty'] }}**{{ $item['order_by'] ? ' by '.$item['order_by'] : '' }}@else — @endif |
@endforeach
</x-mail::table>

<x-mail::button :url="$appUrl">
Open {{ $appName }}
</x-mail::button>

<small>Real-time alerts mention a product at most once every {{ $realertDays }} days (once more if it then sells out), and you get at most {{ $maxPerDay }} of these emails a day, never at night. [Change or turn off real-time alerts]({{ $settingsUrl }}), or mute a single product from its page.</small>
</x-mail::message>
