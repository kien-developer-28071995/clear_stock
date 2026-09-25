@extends('layouts.public')

@section('title', 'Privacy policy')

@section('content')
    <h1>Privacy policy</h1>
    <p><em>Draft — finalized in Phase 7 before App Store submission.</em></p>

    <h2>What we collect</h2>
    <p>{{ $appName }} reads your store's products, inventory levels, locations and orders through the Shopify Admin API
        in order to forecast stock-outs and suggest reorder quantities.</p>
    <p>We store only <strong>daily sales totals per product variant</strong> (units sold, units returned, end-of-day stock).
        We do <strong>not</strong> store raw orders, and we never store customer names, emails, addresses or payment details.</p>

    <h2>What we store about you</h2>
    <p>Your shop domain, an encrypted Shopify access token, your plan, currency and timezone, and the email address you
        choose for stock alerts.</p>

    <h2>Data deletion</h2>
    <p>When you uninstall the app, your access token is revoked immediately and all your shop data is deleted within
        48 hours of Shopify's <code>shop/redact</code> request.</p>

    <h2>Contact</h2>
    <p>Questions: <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></p>
@endsection
