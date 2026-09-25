@extends('layouts.public')

@section('title', 'Support')

@section('content')
    <h1>Support</h1>
    <p>Need help with {{ $appName }}? Email us at <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.
        We reply within one business day.</p>

    <h2>Common questions</h2>
    <p><strong>How is the forecast calculated?</strong> Every forecast in the app has a "Why this number?" explanation
        showing the sales windows, excluded out-of-stock days, lead time and safety stock used.</p>
    <p><strong>Can I cancel anytime?</strong> Yes. Plans are flat monthly or yearly prices billed through Shopify, with no
        contracts. Uninstalling the app cancels billing automatically.</p>
@endsection
