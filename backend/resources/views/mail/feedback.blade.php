<x-mail::message>
# Feedback from {{ $shop->name ?? $shop->domain }}

{{-- Escaped, and shown as written: line breaks kept, no Markdown or HTML of the sender's. --}}
<div style="white-space: pre-wrap;">{{ $text }}</div>

<x-mail::table>
| | |
|:--|:--|
| Store | {{ $shop->domain }} |
| Plan | {{ $plan }} |
| Installed | {{ $installed ?? 'unknown' }} |
| App language | {{ $language ?? 'follows the Shopify admin' }} |
| Reply to | {{ $replyEmail ?? 'no address given' }} |
</x-mail::table>

<small>Sent from the feedback box in {{ $appName }} (Settings).</small>
</x-mail::message>
