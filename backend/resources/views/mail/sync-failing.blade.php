<x-mail::message>
# We can't sync {{ $shop->name ?? $shop->domain }}

The last {{ $failures }} syncs with Shopify failed, so your forecasts and reorder suggestions are
@if ($lastSynced)
based on data from **{{ $lastSynced }}**.
@else
not ready yet.
@endif

**Why:** {{ $reason }}

@if ($reauthorize)
Open {{ $appName }} from your Shopify admin once and the next sync will work again.
@else
We retry automatically every night. You can also start a sync now from Settings.
@endif

<x-mail::button :url="$appUrl">
Open {{ $appName }}
</x-mail::button>

<small>This is the only email about this problem: we won't send another until syncing has worked again. Still failing? [Get help]({{ $supportUrl }}).</small>
</x-mail::message>
