<x-mail::message>
# Your stock this week

**{{ $shop->name ?? $shop->domain }}** · {{ $summary['counts']['total'] }} products forecast.

<x-mail::table>
| | |
|:--|--:|
| Need reordering now | **{{ $summary['counts']['reorder_now'] }}** |
| Out of stock | **{{ $summary['counts']['out_of_stock'] }}** |
| Overstocked | {{ $summary['counts']['overstock'] }} |
| Slow-moving | {{ $summary['counts']['slow'] }} |
@if ($summary['tied_up']['value'] > 0)
| Cash tied up in slow and excess stock | {{ $money($summary['tied_up']['value']) }} |
@endif
@if ($summary['lost_sales'] && $summary['lost_sales']['revenue'] > 0)
| Sales missed while out of stock (30 days) | about {{ $money($summary['lost_sales']['revenue']) }} |
@endif
@if ($summary['inventory'])
| Inventory value at cost | {{ $money($summary['inventory']['value']) }}@if ($summary['inventory']['value_change'] !== null) ({{ $summary['inventory']['value_change'] >= 0 ? '+' : '−' }}{{ $money(abs($summary['inventory']['value_change'])) }} in a week)@endif |
@endif
@if ($summary['accuracy'] !== null)
| Forecast accuracy (latest checked week) | {{ round($summary['accuracy'] * 100) }}% |
@endif
</x-mail::table>

@if ($summary['to_order'] !== [])
## Order first

<x-mail::table>
| Product | In stock | Runs out | Order |
|:--------|--------:|:---------|------:|
@foreach ($summary['to_order'] as $item)
| **{{ $item['name'] }}**{{ $item['sku'] ? ' · '.$item['sku'] : '' }} | {{ $item['stock'] }} | {{ $item['out_of_stock'] ? 'Out of stock' : $item['stockout_date'] }} | **{{ $item['order_qty'] }}**{{ $item['order_by'] ? ' by '.$item['order_by'] : '' }} |
@endforeach
</x-mail::table>
@else
Nothing needs ordering in the next 7 days.
@endif

<x-mail::button :url="$appUrl">
Open {{ $appName }}
</x-mail::button>

<small>One summary a week, on the day you chose. [See the details]({{ $insightsUrl }}) · [Change the day or turn it off]({{ $settingsUrl }}).</small>
</x-mail::message>
