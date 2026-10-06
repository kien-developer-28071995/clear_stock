<x-mail::message>
# Purchase order from {{ $store }}

Hello {{ $supplierName }},

{{ $store }} would like to order the following items.

@if ($note)
<x-mail::panel>
{!! nl2br(e($note)) !!}
</x-mail::panel>
@endif

<x-mail::table>
| SKU | Product | Quantity |
|:----|:--------|---------:|
@foreach ($items as $item)
| {{ ($item['supplier_sku'] ?? null) ?: ($item['sku'] ?: '—') }} | {{ $item['name'] }} | **{{ $item['quantity'] }}** |
@endforeach
| | **Total** | **{{ $totalUnits }}** |
</x-mail::table>

The same list is attached as a CSV file. Please reply to this email to confirm the order and the expected delivery date.

Thank you,<br>
{{ $store }}
</x-mail::message>
