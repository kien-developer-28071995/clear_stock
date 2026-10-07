<x-mail::message>
# Welcome to {{ $appName }}

Thanks for installing {{ $appName }} on **{{ $storeName }}**.

We're reading your sales and stock history from Shopify right now. That usually takes a few minutes, and nothing in your store is changed. When it's done you'll see, for every product:

- **the day it runs out** at the pace it's selling,
- **when to reorder and how many**, based on your supplier lead time,
- **why**: every number comes with a plain explanation, and you can adjust any of them.

<x-mail::button :url="$appUrl">
Open {{ $appName }}
</x-mail::button>

## Three things worth five minutes

1. **Check your lead time.** We start with {{ $leadTimeDays }} days for every product. If your suppliers are faster or slower, [change it in Settings]({{ $settingsUrl }}) and every reorder date follows.
2. **Look at the products running out first.** Home lists the most urgent ones. Open one to see how its forecast was worked out.
3. **Add your suppliers.** Lead time, minimum order and pack size per supplier make the suggested quantities match how you really buy.

The free plan includes real forecasts, reorder suggestions and the full explanations. No contract, and pricing never depends on your sales.

Questions, or a number that looks wrong? Just reply to this email, or visit our [help page]({{ $supportUrl }}).

<small>This is a one-time welcome. After this we only email you about what you switch on in Settings, or if syncing with Shopify stops working.</small>
</x-mail::message>
