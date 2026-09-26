@extends('layouts.public')

@section('title', 'Support')

@section('content')
    <h1>Support</h1>
    <p>Need help with {{ $appName }}? Email us at <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.
        We reply within one business day.</p>

    <h2>Common questions</h2>

    <h3>How is the forecast calculated?</h3>
    <p>Every product has a "Why this number?" explanation: the sales windows used (7, 30 and 90 days), the out-of-stock
        days left out, seasonality, bundles, lead time and safety stock. You can adjust the sales rate, lead time and
        safety stock yourself.</p>

    <h3>Why don't I see forecasts yet?</h3>
    <p>Right after install the app imports your orders and stock in the background (a progress bar shows it). A store
        without orders gets forecasts once it starts selling; products without sales show "no sales yet".</p>

    <h3>I'm moving from Stocky. Can I keep my suppliers?</h3>
    <p>Yes. Export your purchase orders from Stocky as CSV and import them in Suppliers → Import. The app creates your
        suppliers, links products and works out each supplier's lead time.</p>

    <h3>How does billing work? Can I cancel anytime?</h3>
    <p>Plans are a flat monthly or yearly price billed on your Shopify invoice: no share of your sales, no contract.
        Your price never goes up while you stay on your plan. Uninstalling the app cancels billing automatically.</p>

    <h3>What happens to my data if I uninstall?</h3>
    <p>It is deleted 48 hours after uninstall. See the <a href="{{ route('privacy') }}">privacy policy</a>.</p>
@endsection
