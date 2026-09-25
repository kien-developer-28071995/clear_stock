@extends('layouts.public', ['appName' => config('shopify.app_name')])

@section('title', 'Inventory forecasting for Shopify')

@section('content')
    <h1>{{ config('shopify.app_name') }}</h1>
    <p>Know when every variant will sell out and how much to reorder, with forecasts you can read and adjust.</p>
    <p>Install the app from the Shopify App Store, then open it from your Shopify admin under <strong>Apps</strong>.</p>
@endsection
