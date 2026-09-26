@extends('layouts.public')

@section('title', 'Privacy policy')

@section('content')
    <h1>Privacy policy</h1>
    <p><em>Last updated: {{ $updated }}</em></p>

    <p>This policy explains what {{ $appName }} (the "app") reads from your Shopify store, what it keeps, and how to have it
        deleted. The app is used by Shopify merchants; it has no contact with your customers.</p>

    <h2>What the app reads from Shopify</h2>
    <ul>
        <li><strong>Products and variants:</strong> titles, SKU, barcode, vendor, product type, price, unit cost, status.</li>
        <li><strong>Inventory:</strong> available and incoming quantities per location, and your locations.</li>
        <li><strong>Orders:</strong> only the quantity sold and returned of each variant, the order date and, for stores with several
            locations, which location fulfilled it, to build daily sales totals.
            The app never requests customer names, emails, addresses, phone numbers or payment details.</li>
        <li><strong>Store details:</strong> shop domain, name, currency, time zone and contact email (used to email you when no alert email is set).</li>
    </ul>

    <h2>What the app stores</h2>
    <ul>
        <li><strong>Daily sales totals per variant</strong> (units sold, units returned, end-of-day stock) for up to 400 days. Raw orders are not stored.</li>
        <li>Your product catalog details listed above, current stock levels, and the forecasts computed from them.</li>
        <li>What you enter in the app: suppliers (name, email, lead time), settings, bundles and forecast adjustments.</li>
        <li>Your shop domain, an <strong>encrypted</strong> Shopify access token, your plan and the email address you choose for alerts.</li>
        <li>A log of emails the app sent for you (recipient, subject, date, delivery status; not the message), kept 180 days, and
            for purchase orders you email to a supplier, the products and quantities ordered.</li>
    </ul>
    <p>The app stores <strong>no personal data of your customers</strong>. Requests Shopify forwards on behalf of your
        customers (data requests, erasure requests) therefore have nothing to return or delete.</p>

    <h2>How it is used</h2>
    <p>Only to provide the app: forecasting, reorder suggestions, the emails you turn on, and support. The data is not sold,
        shared for advertising, or used to train AI models. No tracking or advertising cookies are used; the app runs inside
        the Shopify admin and signs requests with Shopify session tokens.</p>

    <h2>Service providers</h2>
    <p>The app runs on a hosting provider (servers and database), sends email through an email delivery provider, and
        reports technical errors (without customer data; access tokens are masked) to an internal monitoring channel.
        They process data only on our behalf to run the app.</p>

    <h2>Retention and deletion</h2>
    <ul>
        <li>When you uninstall the app, its access token is removed immediately and billing stops.</li>
        <li>48 hours after uninstall, Shopify asks the app to erase your shop's data and <strong>everything is deleted</strong>
            (sales totals, catalog, forecasts, suppliers, settings, email log). Reinstalling within those 48 hours keeps your data.</li>
        <li>To have your data deleted sooner, or for a copy of it, email {{ $supportEmail }}.</li>
    </ul>

    <h2>Security</h2>
    <p>Traffic is encrypted (HTTPS). Access tokens are encrypted at rest. Webhooks from Shopify are verified by signature.</p>

    <h2>Contact</h2>
    <p>Questions or requests about your data: <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
@endsection
